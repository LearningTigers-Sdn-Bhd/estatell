<?php

use App\Actions\NightAudit\SnapshotStatistics;
use App\Enums\StayType;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\Bed;
use App\Models\DailyRevenue;
use App\Models\DailyStatistic;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\UnitDayUse;
use App\Services\NightAudit\StatisticsCalculator;
use App\Services\Reports\DailyRevenueQuery;
use Carbon\CarbonImmutable;

/**
 * A property with the room revenue, tax and discount codes and its settings.
 *
 * @return array{property: Property, room: TransactionCode, tax: TransactionCode, discount: TransactionCode}
 */
function statsProperty(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();

    return [
        'property' => $property,
        'room' => TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create(),
        'tax' => TransactionCode::factory()->for($property)->system(SystemTransactionCode::SstTax)->create(),
        'discount' => TransactionCode::factory()->for($property)->system(SystemTransactionCode::Discount)->create(),
    ];
}

/**
 * A unit of the given status with one night on the date, assigned to a room or a bed.
 *
 * @param  array<string, mixed>  $attributes
 */
function statsUnit(Property $property, RoomType $roomType, string $status, string $date, Room|Bed|null $place = null, array $attributes = []): ReservationUnit
{
    $factory = ReservationUnit::factory()
        ->for(Reservation::factory()->create(['property_id' => $property->id]))
        ->withNights();
    $unit = ($status === 'confirmed' ? $factory : $factory->{$status}())
        ->create([
            'room_type_id' => $roomType->id,
            'arrival_date' => $date,
            'departure_date' => CarbonImmutable::parse($date)->addDay()->toDateString(),
            ...$attributes,
        ]);

    if ($place instanceof Bed) {
        $unit->nights()->update(['bed_id' => $place->id]);
    } elseif ($place instanceof Room) {
        $unit->nights()->update(['room_id' => $place->id]);
    }

    return $unit;
}

/**
 * One posted line on a new folio of the property.
 *
 * @param  array<string, mixed>  $attributes
 */
function statsLine(Property $property, TransactionCode $code, string $amount, string $date, TransactionKind $kind = TransactionKind::Charge, array $attributes = []): FolioLine
{
    $folio = $attributes['folio'] ?? Folio::factory()->create(['property_id' => $property->id]);
    unset($attributes['folio']);

    return FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'transaction_code_id' => $code->id,
        'kind' => $kind,
        'amount' => $amount,
        'unit_amount' => $amount,
        'posting_date' => $date,
        ...$attributes,
    ]);
}

function statsEvent(Property $property, string $event, string $date, int $unitId): void
{
    activity()->event($event)->withProperties([
        'property_id' => $property->id,
        'reservation_unit_id' => $unitId,
        'business_date' => $date,
    ])->log($event);
}

test('the calculator counts supply, sold units, revenue and front desk events for the date', function () {
    ['property' => $property, 'room' => $room, 'tax' => $tax, 'discount' => $discount] = statsProperty();
    $roomType = RoomType::factory()->for($property)->create();
    $rooms = Room::factory()->count(3)->for($property)->create(['room_type_id' => $roomType->id]);
    RoomBlock::factory()->create(['room_id' => $rooms[2]->id, 'property_id' => $property->id, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-12']);
    $dorm = RoomType::factory()->bed()->for($property)->create();
    $dormRoom = Room::factory()->for($property)->create(['room_type_id' => $dorm->id]);
    $beds = Bed::factory()->count(2)->create(['room_id' => $dormRoom->id]);

    $inHouse = statsUnit($property, $roomType, 'checkedIn', '2026-10-12', $rooms[0], ['adults' => 2, 'children' => 1]);
    statsUnit($property, $dorm, 'checkedIn', '2026-10-12', $beds[0]);
    statsUnit($property, $roomType, 'confirmed', '2026-10-12', $rooms[1]);
    statsUnit($property, $roomType, 'checkedOut', '2026-10-11', $rooms[1]);

    $charge = statsLine($property, $room, '200.00', '2026-10-12', attributes: ['folio' => Folio::factory()->forUnit($inHouse)->create(['property_id' => $property->id, 'reservation_id' => $inHouse->reservation_id])]);
    statsLine($property, $discount, '-20.00', '2026-10-12', TransactionKind::Adjustment, ['folio_id' => $charge->folio_id, 'parent_line_id' => $charge->id]);
    statsLine($property, $tax, '10.80', '2026-10-12', TransactionKind::Tax, ['folio_id' => $charge->folio_id, 'parent_line_id' => $charge->id]);
    statsLine($property, $room, '999.00', '2026-10-11');
    statsLine($property, $room, '-300.00', '2026-10-12', TransactionKind::Payment, ['folio_id' => $charge->folio_id]);

    statsEvent($property, 'reservation.unit_checked_in', '2026-10-12', $inHouse->id);
    statsEvent($property, 'reservation.unit_checked_in', '2026-10-12', $inHouse->id + 1);
    statsEvent($property, 'reservation.unit_checked_in', '2026-10-11', $inHouse->id + 2);
    statsEvent($property, 'reservation.unit_checked_out', '2026-10-12', $inHouse->id + 3);
    statsEvent($property, 'reservation.unit_no_show', '2026-10-12', $inHouse->id + 4);
    statsEvent(Property::factory()->create(), 'reservation.unit_no_show', '2026-10-12', 9999);

    $figures = app(StatisticsCalculator::class)->forDate($property, CarbonImmutable::parse('2026-10-12'));

    expect($figures->roomsAvailable)->toBe(2)
        ->and($figures->roomsSold)->toBe(1)
        ->and($figures->bedsAvailable)->toBe(2)
        ->and($figures->bedsSold)->toBe(1)
        ->and($figures->occupancy)->toBe('50.00')
        ->and((string) $figures->adr->getAmount())->toBe('90.00')
        ->and((string) $figures->revpar->getAmount())->toBe('45.00')
        ->and($figures->arrivals)->toBe(2)
        ->and($figures->departures)->toBe(1)
        ->and($figures->noShows)->toBe(1)
        ->and($figures->inHouseGuests)->toBe(4);
});

test('day use is counted apart from the overnight figures and is saved by the snapshot', function () {
    ['property' => $property, 'room' => $room, 'discount' => $discount] = statsProperty();
    $dayUseCode = TransactionCode::factory()->for($property)->system(SystemTransactionCode::DayUseRevenue)->create();
    $roomType = RoomType::factory()->for($property)->create();
    $rooms = Room::factory()->count(3)->for($property)->create(['room_type_id' => $roomType->id]);
    $overnight = statsUnit($property, $roomType, 'checkedIn', '2026-10-12', $rooms[0]);

    $dayUse = function (string $status, string $date, ?Room $place, ?string $releasedAt = null) use ($property, $roomType): ReservationUnit {
        $factory = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]));
        $unit = ($status === 'confirmed' ? $factory : $factory->{$status}())
            ->create(['room_type_id' => $roomType->id, 'stay_type' => StayType::DayUse, 'arrival_date' => $date, 'departure_date' => $date]);
        UnitDayUse::factory()->create(['reservation_unit_id' => $unit->id, 'date' => $date, 'room_id' => $place?->id, 'released_at' => $releasedAt]);

        return $unit;
    };

    $dayUse('checkedOut', '2026-10-12', $rooms[1]);
    $dayUse('checkedIn', '2026-10-12', $rooms[2]);
    $dayUse('checkedOut', '2026-10-11', $rooms[1]);
    $dayUse('noShow', '2026-10-12', $rooms[1], now()->toDateTimeString());
    $dayUse('confirmed', '2026-10-12', null);

    $charge = statsLine($property, $dayUseCode, '100.00', '2026-10-12');
    statsLine($property, $discount, '-10.00', '2026-10-12', TransactionKind::Adjustment, ['folio_id' => $charge->folio_id, 'parent_line_id' => $charge->id]);
    statsLine($property, $dayUseCode, '55.00', '2026-10-11');
    statsLine($property, $room, '200.00', '2026-10-12');
    $date = CarbonImmutable::parse('2026-10-12');

    $figures = app(StatisticsCalculator::class)->forDate($property, $date);
    app(SnapshotStatistics::class)($property, $date);
    $statistic = DailyStatistic::query()->sole();

    expect($figures->dayUseSold)->toBe(2)
        ->and((string) $figures->dayUseRevenue->getAmount())->toBe('90.00')
        ->and($figures->roomsSold)->toBe(1)
        ->and((string) $figures->adr->getAmount())->toBe('200.00')
        ->and($statistic->day_use_sold)->toBe(2)
        ->and($statistic->day_use_revenue)->toBe('90.00')
        ->and($overnight->id)->not->toBeNull();
});

test('a date with nothing on sale has zero occupancy, ADR and RevPAR', function () {
    ['property' => $property] = statsProperty();

    $figures = app(StatisticsCalculator::class)->forDate($property, CarbonImmutable::parse('2026-10-12'));

    expect($figures->roomsAvailable)->toBe(0)
        ->and($figures->occupancy)->toBe('0.00')
        ->and((string) $figures->adr->getAmount())->toBe('0.00')
        ->and((string) $figures->revpar->getAmount())->toBe('0.00')
        ->and($figures->arrivals)->toBe(0)
        ->and($figures->inHouseGuests)->toBe(0);
});

test('the revenue query groups the lines by date and code, leaves payments out and scopes the property', function () {
    ['property' => $property, 'room' => $room, 'tax' => $tax] = statsProperty();
    $other = statsProperty();

    $charge = statsLine($property, $room, '100.00', '2026-10-12');
    statsLine($property, $tax, '6.00', '2026-10-12', TransactionKind::Tax, ['folio_id' => $charge->folio_id, 'parent_line_id' => $charge->id]);
    $second = statsLine($property, $room, '50.00', '2026-10-12');
    statsLine($property, $room, '-50.00', '2026-10-12', attributes: ['folio_id' => $second->folio_id, 'reversal_of_id' => $second->id]);
    statsLine($property, $room, '70.00', '2026-10-13');
    statsLine($property, $room, '-106.00', '2026-10-12', TransactionKind::Payment);
    statsLine($other['property'], $other['room'], '500.00', '2026-10-12');

    $rows = app(DailyRevenueQuery::class)->between($property, CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-13'));
    $summary = $rows->map(fn ($row): string => $row->date->toDateString().' '.$row->transactionCodeId.' '.$row->amount->getAmount().' '.$row->lineCount)->all();

    expect($summary)->toBe([
        '2026-10-12 '.$room->id.' 100.00 3',
        '2026-10-12 '.$tax->id.' 6.00 1',
        '2026-10-13 '.$room->id.' 70.00 1',
    ])
        ->and($rows->first()->amount->getCurrency()->getCurrencyCode())->toBe('MYR')
        ->and(app(DailyRevenueQuery::class)->forDate($property, CarbonImmutable::parse('2026-10-13'))->count())->toBe(1);
});

test('the snapshot copies the live revenue of the date and a second run changes nothing', function () {
    ['property' => $property, 'room' => $room, 'tax' => $tax] = statsProperty();
    $roomType = RoomType::factory()->for($property)->create();
    $rooms = Room::factory()->count(2)->for($property)->create(['room_type_id' => $roomType->id]);
    $unit = statsUnit($property, $roomType, 'checkedIn', '2026-10-12', $rooms[0]);
    $charge = statsLine($property, $room, '120.00', '2026-10-12');
    statsLine($property, $tax, '7.20', '2026-10-12', TransactionKind::Tax, ['folio_id' => $charge->folio_id, 'parent_line_id' => $charge->id]);
    statsLine($property, $room, '80.00', '2026-10-11');
    $stale = DailyRevenue::factory()->create(['property_id' => $property->id, 'business_date' => '2026-10-12']);
    $date = CarbonImmutable::parse('2026-10-12');

    app(SnapshotStatistics::class)($property, $date);
    $first = [DailyStatistic::query()->get()->toArray(), DailyRevenue::query()->orderBy('transaction_code_id')->get()->toArray()];
    $this->travel(5)->minutes();
    app(SnapshotStatistics::class)($property, $date);

    $live = app(DailyRevenueQuery::class)->forDate($property, $date)->map(fn ($row): array => [$row->transactionCodeId, (string) $row->amount->getAmount(), $row->lineCount])->all();
    $stored = DailyRevenue::query()->where('property_id', $property->id)->orderBy('transaction_code_id')->get()->map(fn (DailyRevenue $row): array => [$row->transaction_code_id, $row->amount, $row->line_count])->all();
    $statistic = DailyStatistic::query()->sole();

    expect($stored)->toBe($live)
        ->and(DailyRevenue::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(DailyRevenue::query()->count())->toBe(2)
        ->and(DailyStatistic::query()->count())->toBe(1)
        ->and($statistic->business_date->toDateString())->toBe('2026-10-12')
        ->and($statistic->rooms_sold)->toBe(1)
        ->and($statistic->rooms_available)->toBe(2)
        ->and($statistic->occupancy)->toBe('50.00')
        ->and($statistic->adr)->toBe('120.00')
        ->and($statistic->revpar)->toBe('60.00')
        ->and($unit->id)->not->toBeNull()
        ->and(array_column($first[1], 'amount'))->toBe(array_column(DailyRevenue::query()->orderBy('transaction_code_id')->get()->toArray(), 'amount'));
});
