<?php

use App\Enums\DefaultRoleEnum;
use App\Models\BusinessDate;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/StayViewHelpers.php';

function roomsSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);

    return [$owner, $property];
}

test('the room view shows one date, the business date by default, with no rates', function () {
    [$owner, $property] = roomsSetup();
    $room = Room::factory()->for($property)->create(['number' => '201']);
    stayViewUnit($property, $room, ['arrival_date' => '2026-10-13', 'departure_date' => '2026-10-16'], guestName: 'Ahmad Faris');

    $this->actingAs($owner)
        ->get(route('front-desk.rooms', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('front-desk/rooms')
            ->where('window.from', '2026-10-14')
            ->where('window.to', '2026-10-14')
            ->where('window.businessDate', '2026-10-14')
            ->has('rooms', 1)
            ->where('rooms.0.label', '201')
            ->has('stays', 1)
            ->where('stays.0.guestLabel', 'Ahmad Faris')
            ->where('blocks', [])
            ->missing('rates')
            ->missing('dayUses')
            ->where('filters.groupBy', 'room_group')
            ->where('filters.show', [])
            ->has('groupingOptions', 3)
            ->has('statusOptions', 3)
            ->has('showOptions', 5)
            ->where('showOptions.2', ['value' => 'departing', 'label' => 'Departing'])
            ->has('stateBadges', 5)
            ->where('stateBadges.0', ['value' => 'arriving', 'label' => 'Arriving', 'tone' => 'info'])
            ->where('canViewReservations', true));
});

test('a guest who leaves on the date is loaded, a guest who left the day before is not', function () {
    [$owner, $property] = roomsSetup();
    stayViewUnit($property, Room::factory()->for($property)->create(), ['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14'], guestName: 'Leaving Today');
    stayViewUnit($property, Room::factory()->for($property)->create(), ['arrival_date' => '2026-10-11', 'departure_date' => '2026-10-13'], guestName: 'Left Yesterday');
    stayViewUnit($property, Room::factory()->for($property)->create(), ['arrival_date' => '2026-10-14', 'departure_date' => '2026-10-15'], guestName: 'Arriving Today');
    stayViewUnit($property, Room::factory()->for($property)->create(), ['arrival_date' => '2026-10-15', 'departure_date' => '2026-10-16'], guestName: 'Arriving Tomorrow');

    $this->actingAs($owner)
        ->get(route('front-desk.rooms', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 2)
            ->where('stays', fn ($stays) => collect($stays)->pluck('guestLabel')->sort()->values()->all() === ['Arriving Today', 'Leaving Today']));
});

test('the date, the filters and Show come from the query', function () {
    [$owner, $property] = roomsSetup();
    Room::factory()->for($property)->create();
    RoomBlock::factory()->create(['property_id' => $property->id, 'room_id' => Room::query()->first()->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-22']);

    $this->actingAs($owner)
        ->get(route('front-desk.rooms', [$property->code, 'date' => '2026-10-21', 'groups' => [3], 'types' => [4], 'statuses' => ['dirty'], 'show' => ['arriving', 'vacant'], 'q' => 'Ahmad', 'group_by' => 'none']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('window.from', '2026-10-21')
            ->where('window.to', '2026-10-21')
            ->has('blocks', 1)
            ->where('filters.roomGroupIds', [3])
            ->where('filters.roomTypeIds', [4])
            ->where('filters.statuses', ['dirty'])
            ->where('filters.show', ['arriving', 'vacant'])
            ->where('filters.search', 'Ahmad')
            ->where('filters.groupBy', 'none'));
});

test('a bad date falls back to the business date and a bad filter is refused', function () {
    [$owner, $property] = roomsSetup();

    foreach (['2026-13-45', 'tomorrow', '14/10/2026'] as $date) {
        $this->actingAs($owner)
            ->get(route('front-desk.rooms', [$property->code, 'date' => $date]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('window.from', '2026-10-14')->where('window.to', '2026-10-14'));
    }

    $this->actingAs($owner)
        ->get(route('front-desk.rooms', [$property->code, 'show' => ['asleep'], 'group_by' => 'colour']))
        ->assertSessionHasErrors(['show.0', 'group_by']);
});

test('the Room View sits right after the Timeline View and is active on its page', function () {
    [$owner, $property] = roomsSetup();

    $this->actingAs($owner)
        ->get(route('front-desk.rooms', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.contexts.0.groups', function ($groups) {
                $items = collect(collect($groups)->firstWhere('label', 'Overview')['items']);

                expect($items->pluck('title')->all())->toBe(['Dashboard', 'Timeline View', 'Room View'])
                    ->and($items->firstWhere('title', 'Room View')['active'])->toBeTrue()
                    ->and($items->firstWhere('title', 'Timeline View')['active'])->toBeFalse();

                return true;
            }));
});

test('staff without front-desk.view cannot open the room view', function () {
    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create();

    $this->actingAs($finance)->get(route('front-desk.rooms', $finance->property->code))->assertForbidden();
});

test('front desk staff can open the room view, and the reservation link needs reservations.view', function () {
    [$owner, $property] = roomsSetup();
    $viewer = User::factory()->for($property)->withPermissions(['front-desk.view'])->create();

    $this->actingAs($viewer)
        ->get(route('front-desk.rooms', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canViewReservations', false));
});

test('the room view shows only its own property', function () {
    [$owner, $property] = roomsSetup();
    $other = User::factory()->owner()->create()->property;
    $room = Room::factory()->for($property)->create(['number' => '201']);
    $otherRoom = Room::factory()->for($other)->create(['number' => '999']);
    stayViewUnit($property, $room, guestName: 'Mine');
    stayViewUnit($other, $otherRoom, guestName: 'Theirs');
    RoomBlock::factory()->create(['property_id' => $other->id, 'room_id' => $otherRoom->id, 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-15']);

    $this->actingAs($owner)
        ->get(route('front-desk.rooms', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rooms', 1)
            ->where('rooms.0.label', '201')
            ->has('stays', 1)
            ->where('stays.0.guestLabel', 'Mine')
            ->where('blocks', []));

    $this->actingAs($owner)->get(route('front-desk.rooms', $other->code))->assertNotFound();
});

test('the page runs the same number of queries however many stays it shows', function () {
    [$owner, $property] = roomsSetup();
    $reservation = Reservation::factory()->group('ABC Corp')->create(['property_id' => $property->id]);
    $countQueries = function () use ($owner, $property): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->get(route('front-desk.rooms', $property->code))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $addStay = function (int $index) use ($property, $reservation): void {
        stayViewUnit($property, Room::factory()->for($property)->create(), ['arrival_date' => '2026-10-13'], reservation: $index % 2 === 0 ? $reservation : null, guestName: 'Guest '.$index);
    };

    $addStay(1);
    $countQueries();
    $before = $countQueries();

    foreach (range(2, 7) as $index) {
        $addStay($index);
    }

    expect($countQueries())->toBe($before)
        ->and(ReservationUnit::query()->count())->toBe(7);
});

test('the room view carries the booking and block props, each needing its own permission', function () {
    [$owner, $property] = roomsSetup();
    $viewer = User::factory()->for($property)->withPermissions(['front-desk.view'])->create();
    $booker = User::factory()->for($property)->withPermissions(['front-desk.view', 'reservations.manage'])->create();

    $this->actingAs($owner)
        ->get(route('front-desk.rooms', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManageReservations', true)
            ->where('canBlockRooms', true)
            ->has('blockReasons', 4)
            ->where('blockReasons.0.value', 'maintenance')
            ->where('maxBlockNights', 365));

    $this->actingAs($viewer)
        ->get(route('front-desk.rooms', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('canManageReservations', false)->where('canBlockRooms', false));

    $this->actingAs($booker)
        ->get(route('front-desk.rooms', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('canManageReservations', true)->where('canBlockRooms', false));
});
