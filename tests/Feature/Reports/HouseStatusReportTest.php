<?php

use App\Data\Reports\HouseStatusRow;
use App\Data\Reports\ReportFilters;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\ChargingUnit;
use App\Enums\RoomBlockReason;
use App\Enums\UnitStatus;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\ChargeItem;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\Tag;
use App\Models\UnitAddOn;
use App\Models\UnitBoatTrip;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Services\Reports\HouseStatusReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\View;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/ReportSetup.php';

/**
 * A unit with `$adults` adults and a night in a room for each night of the stay; `$rooms` maps a night to a room
 * number when it differs from `$room`.
 *
 * @param  array<string, string>  $rooms
 */
function houseStatusUnit(Property $property, RoomType $type, string $room, string $arrival, string $departure, int $adults = 2, array $rooms = [], UnitStatus $status = UnitStatus::Confirmed): ReservationUnit
{
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'arrival_date' => $arrival,
        'departure_date' => $departure,
        'departure_time' => '11:00:00',
        'arrival_time' => '15:00:00',
        'adults' => $adults,
        'children' => 0,
    ]);
    $unit->forceFill(['status' => $status, ...($status === UnitStatus::CheckedOut ? ['checked_in_at' => now(), 'checked_out_at' => now()] : [])])->save();

    for ($night = CarbonImmutable::parse($arrival); $night->lt(CarbonImmutable::parse($departure)); $night = $night->addDay()) {
        $number = $rooms[$night->toDateString()] ?? $room;
        UnitNight::factory()->create([
            'reservation_unit_id' => $unit->id,
            'date' => $night->toDateString(),
            'room_id' => Room::query()->where('property_id', $property->id)->where('number', $number)->value('id'),
        ]);
    }

    return $unit;
}

function houseStatusTrip(ReservationUnit $unit, BoatDirection $direction, ?BoatDeparture $departure, BoatMode $mode = BoatMode::Scheduled): void
{
    UnitBoatTrip::factory()->create([
        'property_id' => $unit->property_id,
        'reservation_unit_id' => $unit->id,
        'direction' => $direction,
        'mode' => $mode,
        'boat_departure_id' => $departure?->id,
    ]);
}

/**
 * @return list<HouseStatusRow>
 */
function houseStatusRows(Property $property, string $date = '2026-10-20'): array
{
    return app(HouseStatusReport::class)->rows($property, new ReportFilters(from: $date, to: $date));
}

test('rooms group by boat time, then boat not set, room move, stay-over and management, with labels and pax', function () {
    [, $property] = reportSetup();
    Feature::for($property)->activate(Boats::class);
    $king = RoomType::factory()->for($property)->create(['name' => 'Deluxe Super King', 'print_label' => 'SKING']);
    $twin = RoomType::factory()->for($property)->create(['name' => 'Standard Twin']);
    foreach (['A05' => $king, 'B01' => $twin, 'C02' => $twin, 'D04' => $twin, 'E01' => $twin, 'E02' => $twin, 'F01' => $twin] as $number => $type) {
        Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => $number, 'print_label' => $number === 'D04' ? 'QUADRUPLE' : null]);
    }
    $out = BoatDeparture::factory()->for($property)->out()->create(['leaves_at' => '11:30', 'arrives_at' => '12:15']);
    $in = BoatDeparture::factory()->for($property)->create(['direction' => BoatDirection::In, 'leaves_at' => '12:30', 'arrives_at' => '13:15']);

    $leaving = houseStatusUnit($property, $king, 'A05', '2026-10-18', '2026-10-20', status: UnitStatus::CheckedOut);
    houseStatusTrip($leaving, BoatDirection::Out, $out);
    $arriving = houseStatusUnit($property, $twin, 'B01', '2026-10-20', '2026-10-22', adults: 3);
    houseStatusTrip($arriving, BoatDirection::In, $in);
    houseStatusUnit($property, $twin, 'C02', '2026-10-20', '2026-10-21');
    houseStatusUnit($property, $twin, 'D04', '2026-10-19', '2026-10-22', adults: 4);
    houseStatusUnit($property, $twin, 'E01', '2026-10-19', '2026-10-22', rooms: ['2026-10-20' => 'E02', '2026-10-21' => 'E02']);
    RoomBlock::factory()->create(['room_id' => Room::query()->where('number', 'F01')->value('id'), 'reason' => RoomBlockReason::OwnerUse, 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-21']);

    $rows = houseStatusRows($property);
    $sections = app(HouseStatusReport::class)->sections($rows, new ReportFilters(from: '2026-10-20', to: '2026-10-20'));

    expect(array_map(fn (HouseStatusRow $row): array => [$row->group, $row->room, $row->label, $row->pax, $row->checked_out], $rows))->toBe([
        ['Boat out · 11:30', 'A05', 'SKING', 2, true],
        ['Boat in · 12:30', 'B01', 'ST', 3, false],
        ['Boat not set', 'C02', 'ST', 2, false],
        ['Room move', 'E01', 'ST', 2, false],
        ['Room move', 'E02', 'ST', 2, false],
        ['OCC (stay-over)', 'D04', 'QUADRUPLE', 4, false],
        ['Management', 'F01', 'ST', 0, false],
    ])
        ->and(array_map(fn ($section): string => $section->title.' | '.$section->subtotal, $sections))->toBe([
            'Boat out · 11:30 | 2 pax · 1 room',
            'Boat in · 12:30 | 3 pax · 1 room',
            'Boat not set | 2 pax · 1 room',
            'Room move | 2 pax · 1 room',
            'OCC (stay-over) | 4 pax · 1 room',
            'Management | 0 pax · 1 room',
        ]);
});

test('a tile shows the extra bed, a tour leader and the room remark', function () {
    [, $property] = reportSetup();
    $twin = RoomType::factory()->for($property)->create(['name' => 'Standard Twin']);
    $room = Room::factory()->for($property)->create(['room_type_id' => $twin->id, 'number' => 'B05']);
    $room->status()->updateOrCreate([], ['remark' => 'Aircon noisy']);
    $unit = houseStatusUnit($property, $twin, 'B05', '2026-10-19', '2026-10-22');
    $bed = ChargeItem::factory()->for($property)->create(['name' => 'Extra bed', 'charging_unit' => ChargingUnit::PerStay, 'housekeeping_item' => true]);
    (new UnitAddOn)->forceFill(['reservation_unit_id' => $unit->id, 'charge_item_id' => $bed->id, 'quantity' => 1, 'unit_amount' => '50.00'])->save();
    $leader = Guest::factory()->create(['property_id' => $property->id]);
    $leader->tags()->attach(Tag::factory()->create(['property_id' => $property->id, 'name' => 'TL']));
    UnitGuest::factory()->primary()->create(['reservation_unit_id' => $unit->id, 'guest_id' => $leader->id]);

    [$row] = houseStatusRows($property);

    expect($row->flags)->toBe(['EXTRA BED', 'TL'])->and($row->remark)->toBe('Aircon noisy');
});

test('without the Boats feature rooms group by their expected time', function () {
    [, $property] = reportSetup();
    $twin = RoomType::factory()->for($property)->create(['name' => 'Standard Twin']);
    Room::factory()->for($property)->create(['room_type_id' => $twin->id, 'number' => 'B01']);
    houseStatusUnit($property, $twin, 'B01', '2026-10-20', '2026-10-22');

    expect(houseStatusRows($property)[0]->group)->toBe('In · 15:00');
});

test('the PDF prints the grid with the groups and the extra amenities', function () {
    [$manager, $property] = reportSetup();
    $twin = RoomType::factory()->for($property)->create(['name' => 'Standard Twin']);
    Room::factory()->for($property)->create(['room_type_id' => $twin->id, 'number' => 'B01']);
    houseStatusUnit($property, $twin, 'B01', '2026-10-20', '2026-10-22');
    $captured = new stdClass;
    $captured->data = null;
    View::composer('pdf.house-status', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    $this->actingAs($manager)->post("/p/{$property->code}/reports/house-status/pdf", ['date' => '2026-10-20'])->assertOk();

    expect($captured->data['workingDate'])->toBe('20/10/2026')
        ->and($captured->data['groups'][0]['title'])->toBe('In · 15:00')
        ->and($captured->data['groups'][0]['total'])->toBe('2 pax · 1 room');
});
