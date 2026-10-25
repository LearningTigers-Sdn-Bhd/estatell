<?php

use App\Enums\HousekeepingStatus;
use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomGroup;
use App\Models\RoomType;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Services\StayView\StayViewQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/StayViewHelpers.php';

function stayViewRange(string $from = '2026-10-14', string $to = '2026-10-27'): array
{
    return [CarbonImmutable::parse($from), CarbonImmutable::parse($to)];
}

test('the window carries the business date and today in the property time zone', function () {
    $property = stayViewProperty();
    [$from, $to] = stayViewRange();

    $window = app(StayViewQuery::class)->window($property, $from, $to);

    expect($window->from)->toBe('2026-10-14')
        ->and($window->to)->toBe('2026-10-27')
        ->and($window->businessDate)->toBe('2026-10-14');
});

test('rows list active rooms, and the beds of rooms that sell beds, in room order', function () {
    $property = stayViewProperty();
    $group = RoomGroup::factory()->for($property)->create(['name' => 'Left wing']);
    $deluxe = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    $dorm = RoomType::factory()->for($property)->bed()->create(['name' => 'Dorm bed']);
    $room = Room::factory()->for($property)->create(['room_type_id' => $deluxe->id, 'room_group_id' => $group->id, 'number' => '201', 'position' => 1]);
    $room->status->update(['status' => HousekeepingStatus::Dirty, 'is_priority' => true, 'do_not_disturb_on' => '2026-10-14']);
    $dormRoom = Room::factory()->for($property)->create(['room_type_id' => $dorm->id, 'number' => 'Dorm A', 'position' => 2]);
    $bed = Bed::factory()->for($dormRoom)->create(['label' => 'A1', 'position' => 1]);
    Bed::factory()->for($dormRoom)->archived()->create(['label' => 'A2', 'position' => 2]);
    Room::factory()->for($property)->archived()->create(['number' => '999']);
    Room::factory()->create(['number' => '500']);

    $rows = app(StayViewQuery::class)->rows($property, CarbonImmutable::parse('2026-10-14'));

    expect(collect($rows)->pluck('key')->all())->toBe(['room-'.$room->id, 'bed-'.$bed->id])
        ->and($rows[0]->label)->toBe('201')
        ->and($rows[0]->roomGroupName)->toBe('Left wing')
        ->and($rows[0]->roomTypeName)->toBe('Deluxe')
        ->and($rows[0]->sellUnit)->toBe(SellUnit::Room)
        ->and($rows[0]->housekeepingStatus)->toBe(HousekeepingStatus::Dirty)
        ->and($rows[0]->isPriority)->toBeTrue()
        ->and($rows[0]->doNotDisturb)->toBeTrue()
        ->and($rows[1]->label)->toBe('A1')
        ->and($rows[1]->roomNumber)->toBe('Dorm A')
        ->and($rows[1]->sellUnit)->toBe(SellUnit::Bed)
        ->and($rows[1]->roomGroupId)->toBeNull()
        ->and($rows[1]->doNotDisturb)->toBeFalse();
});

test('a room move starts a new segment and consecutive nights in one room form one', function () {
    $property = stayViewProperty();
    $first = Room::factory()->for($property)->create(['number' => '201']);
    $second = Room::factory()->for($property)->create(['number' => '202']);
    $unit = stayViewUnit($property, $first, ['arrival_date' => '2026-10-14', 'departure_date' => '2026-10-18']);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->whereDate('date', '>=', '2026-10-16')->update(['room_id' => $second->id]);
    [$from, $to] = stayViewRange();

    $stays = app(StayViewQuery::class)->stays($property, $from, $to);

    expect($stays)->toHaveCount(1)
        ->and($stays[0]->guestLabel)->toBe('Hanami Saki')
        ->and($stays[0]->arrivalDate)->toBe('2026-10-14')
        ->and($stays[0]->departureDate)->toBe('2026-10-18')
        ->and(array_map(fn ($segment): array => [$segment->rowKey, $segment->firstNight, $segment->lastNight], $stays[0]->segments))->toBe([
            ['room-'.$first->id, '2026-10-14', '2026-10-15'],
            ['room-'.$second->id, '2026-10-16', '2026-10-17'],
        ]);
});

test('a bed stay has a bed segment, and a night without a place is in the unassigned row of its room type', function () {
    $property = stayViewProperty();
    $dormRoom = Room::factory()->for($property)->create(['room_type_id' => RoomType::factory()->for($property)->bed()->create()->id]);
    $bed = Bed::factory()->for($dormRoom)->create();
    $unit = stayViewUnit($property, $bed, ['arrival_date' => '2026-10-14', 'departure_date' => '2026-10-17']);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->whereDate('date', '2026-10-15')->update(['bed_id' => null]);
    [$from, $to] = stayViewRange();

    $stays = app(StayViewQuery::class)->stays($property, $from, $to);

    expect(array_map(fn ($segment): array => [$segment->rowKey, $segment->firstNight, $segment->lastNight], $stays[0]->segments))->toBe([
        ['bed-'.$bed->id, '2026-10-14', '2026-10-14'],
        ['unassigned-'.$unit->room_type_id, '2026-10-15', '2026-10-15'],
        ['bed-'.$bed->id, '2026-10-16', '2026-10-16'],
    ]);
});

test('consecutive nights with no room form one unassigned segment', function () {
    $property = stayViewProperty();
    $room = Room::factory()->for($property)->create();
    $unit = stayViewUnit($property, $room, ['arrival_date' => '2026-10-14', 'departure_date' => '2026-10-19']);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->whereDate('date', '>=', '2026-10-15')->whereDate('date', '<=', '2026-10-16')->update(['room_id' => null]);
    [$from, $to] = stayViewRange();

    $stays = app(StayViewQuery::class)->stays($property, $from, $to);

    expect(array_map(fn ($segment): array => [$segment->rowKey, $segment->firstNight, $segment->lastNight], $stays[0]->segments))->toBe([
        ['room-'.$room->id, '2026-10-14', '2026-10-14'],
        ['unassigned-'.$unit->room_type_id, '2026-10-15', '2026-10-16'],
        ['room-'.$room->id, '2026-10-17', '2026-10-18'],
    ]);
});

test('segments are cut to the window and units outside it are left out', function () {
    $property = stayViewProperty();
    $room = fn (): Room => Room::factory()->for($property)->create();
    stayViewUnit($property, $room(), ['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-16']);
    stayViewUnit($property, $room(), ['arrival_date' => '2026-10-27', 'departure_date' => '2026-10-29'], guestName: 'Last Night');
    stayViewUnit($property, $room(), ['arrival_date' => '2026-10-28', 'departure_date' => '2026-10-30'], guestName: 'After');
    stayViewUnit($property, $room(), ['arrival_date' => '2026-10-08', 'departure_date' => '2026-10-14'], guestName: 'Left Today');
    [$from, $to] = stayViewRange();

    $stays = app(StayViewQuery::class)->stays($property, $from, $to);

    expect(collect($stays)->pluck('guestLabel')->all())->toBe(['Hanami Saki', 'Last Night'])
        ->and($stays[0]->segments[0]->firstNight)->toBe('2026-10-14')
        ->and($stays[0]->segments[0]->lastNight)->toBe('2026-10-15')
        ->and($stays[1]->segments[0]->firstNight)->toBe('2026-10-27')
        ->and($stays[1]->segments[0]->lastNight)->toBe('2026-10-27');
});

test('cancelled and no-show units and other properties are left out', function () {
    $property = stayViewProperty();
    $room = fn (): Room => Room::factory()->for($property)->create();
    stayViewUnit($property, $room(), guestName: 'Confirmed Guest');
    stayViewUnit($property, $room(), guestName: 'Cancelled Guest', state: 'cancelled');
    stayViewUnit($property, $room(), guestName: 'No Show Guest', state: 'noShow');
    $other = stayViewProperty();
    stayViewUnit($other, Room::factory()->for($other)->create(), guestName: 'Other Property');
    [$from, $to] = stayViewRange();

    $stays = app(StayViewQuery::class)->stays($property, $from, $to);

    expect(collect($stays)->pluck('guestLabel')->all())->toBe(['Confirmed Guest']);
});

test('units of a group booking share the reservation and group name', function () {
    $property = stayViewProperty();
    $group = Reservation::factory()->group('ABC Corp')->create(['property_id' => $property->id]);
    $single = Reservation::factory()->create(['property_id' => $property->id]);
    stayViewUnit($property, Room::factory()->for($property)->create(), reservation: $group, guestName: 'Tan Mei');
    stayViewUnit($property, Room::factory()->for($property)->create(), reservation: $group, guestName: 'Raj K');
    stayViewUnit($property, Room::factory()->for($property)->create(), reservation: $single, guestName: 'Lim Wei');
    [$from, $to] = stayViewRange();

    $stays = app(StayViewQuery::class)->stays($property, $from, $to);

    expect(collect($stays)->where('groupName', 'ABC Corp')->pluck('reservationId')->unique()->all())->toBe([$group->id])
        ->and(collect($stays)->where('groupName', 'ABC Corp'))->toHaveCount(2)
        ->and(collect($stays)->firstWhere('guestLabel', 'Lim Wei')->groupName)->toBeNull();
});

test('a stay carries its status badge, pax and times but no contact or ID data', function () {
    $property = stayViewProperty();
    stayViewUnit($property, Room::factory()->for($property)->create(), ['adults' => 2, 'children' => 1, 'arrival_time' => '14:00:00', 'departure_time' => '12:00:00'], state: 'checkedIn');
    [$from, $to] = stayViewRange();

    $stay = app(StayViewQuery::class)->stays($property, $from, $to)[0];

    expect($stay->status->value)->toBe('checked_in')
        ->and($stay->adults)->toBe(2)
        ->and($stay->children)->toBe(1)
        ->and($stay->arrivalTime)->toBe('14:00')
        ->and($stay->departureTime)->toBe('12:00')
        ->and(array_keys($stay->toArray()))->not->toContain('email', 'phone', 'identityDocuments');
});

test('a stay carries the badge of its booking source', function () {
    $property = stayViewProperty();
    $walkIn = Reservation::factory()->walkIn()->create(['property_id' => $property->id]);
    stayViewUnit($property, Room::factory()->for($property)->create(), reservation: $walkIn, guestName: 'Walk Guest');
    [$from, $to] = stayViewRange();

    $stay = app(StayViewQuery::class)->stays($property, $from, $to)[0];

    expect($stay->source->name)->toBe('Walk-in')
        ->and($stay->source->type->value)->toBe('walk_in')
        ->and($stay->source->icon !== null || $stay->source->letters !== null)->toBeTrue();
});

test('a stay carries the units its whole reservation holds, not the cancelled ones', function () {
    $property = stayViewProperty();
    $group = Reservation::factory()->group('ABC Corp')->create(['property_id' => $property->id]);
    $single = Reservation::factory()->create(['property_id' => $property->id]);
    stayViewUnit($property, Room::factory()->for($property)->create(), reservation: $group, guestName: 'Raj K');
    stayViewUnit($property, Room::factory()->for($property)->create(), reservation: $group, guestName: 'Tan M');
    stayViewUnit($property, Room::factory()->for($property)->create(), reservation: $group, guestName: 'Cancelled Guest', state: 'cancelled');
    stayViewUnit($property, Room::factory()->for($property)->create(), reservation: $single, guestName: 'Lim Wei');
    [$from, $to] = stayViewRange();

    $stays = collect(app(StayViewQuery::class)->stays($property, $from, $to));

    expect($stays->firstWhere('guestLabel', 'Raj K')->unitCount)->toBe(2)
        ->and($stays->firstWhere('guestLabel', 'Lim Wei')->unitCount)->toBe(1);
});

test('balance due is set when the reservation owes money', function () {
    $property = stayViewProperty();
    $owing = Reservation::factory()->create(['property_id' => $property->id]);
    $paid = Reservation::factory()->create(['property_id' => $property->id]);
    stayViewUnit($property, Room::factory()->for($property)->create(), reservation: $owing, guestName: 'Owes');
    stayViewUnit($property, Room::factory()->for($property)->create(), reservation: $paid, guestName: 'Paid');
    $owingFolio = Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $owing->id]);
    FolioLine::factory()->create(['folio_id' => $owingFolio->id, 'amount' => '120.00']);
    $paidFolio = Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $paid->id]);
    FolioLine::factory()->create(['folio_id' => $paidFolio->id, 'amount' => '100.00']);
    FolioLine::factory()->create(['folio_id' => $paidFolio->id, 'amount' => '-100.00']);
    [$from, $to] = stayViewRange();

    $stays = collect(app(StayViewQuery::class)->stays($property, $from, $to));

    expect($stays->firstWhere('guestLabel', 'Owes')->balanceDue)->toBeTrue()
        ->and($stays->firstWhere('guestLabel', 'Paid')->balanceDue)->toBeFalse();
});

test('blocks list open blocks that share a night with the window', function () {
    $property = stayViewProperty();
    $room = Room::factory()->for($property)->create();
    $inside = RoomBlock::factory()->create(['room_id' => $room->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-22']);
    RoomBlock::factory()->create(['room_id' => $room->id, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-13']);
    RoomBlock::factory()->create(['room_id' => $room->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-22', 'completed_at' => now()]);
    RoomBlock::factory()->create(['starts_on' => '2026-10-20', 'ends_on' => '2026-10-22']);
    [$from, $to] = stayViewRange();

    $blocks = app(StayViewQuery::class)->blocks($property, $from, $to);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]->id)->toBe($inside->id)
        ->and($blocks[0]->roomId)->toBe($room->id)
        ->and($blocks[0]->bedId)->toBeNull()
        ->and($blocks[0]->firstNight)->toBe('2026-10-20')
        ->and($blocks[0]->lastNight)->toBe('2026-10-22')
        ->and($blocks[0]->reason)->toBe('maintenance');
});

test('the number of queries does not grow with the number of stays', function () {
    $property = stayViewProperty();
    $group = Reservation::factory()->group('ABC Corp')->create(['property_id' => $property->id]);
    [$from, $to] = stayViewRange();
    $query = app(StayViewQuery::class);
    $countQueries = function () use ($query, $property, $from, $to): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $query->rows($property, $from);
        $query->stays($property, $from, $to);
        $query->blocks($property, $from, $to);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $addStay = function (int $index) use ($property, $group): void {
        $room = Room::factory()->for($property)->create();
        $reservation = $index % 2 === 0 ? $group : Reservation::factory()->create(['property_id' => $property->id]);
        stayViewUnit($property, $room, reservation: $reservation, guestName: 'Guest '.$index);

        if ($index % 2 !== 0) {
            FolioLine::factory()->create(['folio_id' => Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id])->id]);
        }

        RoomBlock::factory()->create(['room_id' => $room->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-21']);

        $unassigned = stayViewUnit($property, Room::factory()->for($property)->create(), reservation: $reservation, guestName: 'Auto '.$index);
        UnitNight::query()->where('reservation_unit_id', $unassigned->id)->update(['room_id' => null]);
    };

    $addStay(1);
    $countQueries();
    $before = $countQueries();

    foreach (range(2, 7) as $index) {
        $addStay($index);
    }

    expect($countQueries())->toBe($before)
        ->and(count($query->stays($property, $from, $to)))->toBe(14);
});

test('day uses lists live blocks with a room in the window and leaves out released, roomless and other dates', function () {
    $property = stayViewProperty();
    $room = Room::factory()->for($property)->create(['number' => '201']);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $dayUse = fn (string $startsAt, array $attributes = [], string $date = '2026-10-15') => ReservationUnit::factory()->dayUse($date, $startsAt, 2, $room->id)->create([
        'property_id' => $property->id,
        'reservation_id' => $reservation->id,
        ...$attributes,
    ]);

    $live = $dayUse('12:00');
    UnitGuest::factory()->primary()->create([
        'reservation_unit_id' => $live->id,
        'guest_id' => Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Hanami Saki'])->id,
    ]);
    $released = $dayUse('15:00');
    $released->dayUse->update(['released_at' => now()]);
    ReservationUnit::factory()->dayUse('2026-10-15', '12:00', 2)->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]);
    $dayUse('12:00', date: '2026-11-20');
    $dayUse('18:00', ['status' => UnitStatus::Cancelled]);

    [$from, $to] = stayViewRange();
    $result = app(StayViewQuery::class)->dayUses($property, $from, $to);

    expect($result)->toHaveCount(1)
        ->and($result[0]->unitId)->toBe($live->id)
        ->and($result[0]->roomId)->toBe($room->id)
        ->and($result[0]->date)->toBe('2026-10-15')
        ->and($result[0]->startsAt)->toBe('12:00')
        ->and($result[0]->endsAt)->toBe('14:00')
        ->and($result[0]->hours)->toBe(2)
        ->and($result[0]->guestLabel)->toBe('Hanami Saki');
});
