<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\HousekeepingStatus;
use App\Models\Bed;
use App\Models\BusinessDate;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(9, 0));
});

function boardProperty(User $user): Property
{
    BusinessDate::factory()->create(['property_id' => $user->property_id, 'date' => '2026-10-12']);

    return $user->property;
}

function boardRoom(Property $property, string $number, HousekeepingStatus $status = HousekeepingStatus::Clean): Room
{
    $room = Room::factory()->for($property)->create(['number' => $number]);
    $room->status->update(['status' => $status]);

    return $room;
}

/**
 * A unit with nights in `$room`, and a primary guest.
 *
 * @param  array<string, mixed>  $attributes
 */
function boardUnit(Property $property, Room $room, array $attributes, string $guestName = 'Hanami Saki', ?string $state = null): ReservationUnit
{
    $factory = ReservationUnit::factory()->withNights();
    $unit = ($state === null ? $factory : $factory->{$state}())->create([
        'reservation_id' => Reservation::factory()->create(['property_id' => $property->id])->id,
        ...$attributes,
    ]);

    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['room_id' => $room->id]);
    UnitGuest::factory()->primary()->create([
        'reservation_unit_id' => $unit->id,
        'guest_id' => Guest::factory()->create(['property_id' => $property->id, 'full_name' => $guestName])->id,
    ]);

    return $unit;
}

test('the board lists every active room with its status, stay and reason, departures first', function () {
    $owner = User::factory()->owner()->create();
    $property = boardProperty($owner);
    $departed = boardRoom($property, '101', HousekeepingStatus::Dirty);
    $inHouse = boardRoom($property, '102', HousekeepingStatus::Dirty);
    $arriving = boardRoom($property, '103');
    $idle = boardRoom($property, '104', HousekeepingStatus::Inspected);
    Room::factory()->for($property)->archived()->create(['number' => '999']);

    boardUnit($property, $departed, ['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12', 'departure_time' => '11:00'], 'Departed Guest', 'checkedOut');
    boardUnit($property, $inHouse, ['arrival_date' => '2026-10-11', 'departure_date' => '2026-10-14'], 'Staying Guest', 'checkedIn');
    boardUnit($property, $arriving, ['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-13', 'arrival_time' => '14:30'], 'Arriving Guest');

    $this->actingAs($owner)
        ->get(route('housekeeping.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->component('housekeeping/index')
            ->where('date', '2026-10-12')
            ->has('rooms', 4)
            ->where('rooms.0.number', '101')
            ->where('rooms.0.status.value', 'dirty')
            ->where('rooms.0.reason', 'Checked out today')
            ->where('rooms.0.guest', 'Departed Guest')
            ->where('rooms.0.departureTime', '11:00')
            ->has('rooms.0.moves', 1)
            ->where('rooms.0.moves.0.value', 'clean')
            ->where('rooms.0.moves.0.label', 'Clean')
            ->where('rooms.1.number', '103')
            ->where('rooms.1.arrivalTime', '14:30')
            ->where('rooms.2.number', '102')
            ->where('rooms.2.isStayOver', true)
            ->where('rooms.2.reason', 'Guest in house')
            ->where('rooms.3.number', '104')
            ->where('rooms.3.moves.0.value', 'dirty')
            ->where('can.update', true));

    expect($idle->status->status)->toBe(HousekeepingStatus::Inspected);
});

test('the board orders departures by departure time, then arrivals by arrival time, then the rest by room', function () {
    $owner = User::factory()->owner()->create();
    $property = boardProperty($owner);
    $idle = boardRoom($property, '101', HousekeepingStatus::Inspected);
    $lateArrival = boardRoom($property, '102');
    $lateDeparture = boardRoom($property, '103', HousekeepingStatus::Dirty);
    $earlyArrival = boardRoom($property, '104');
    $earlyDeparture = boardRoom($property, '105', HousekeepingStatus::Dirty);
    $inHouse = boardRoom($property, '106', HousekeepingStatus::Dirty);

    boardUnit($property, $lateArrival, ['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-13', 'arrival_time' => '15:00'], 'Late Arrival');
    boardUnit($property, $lateDeparture, ['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12', 'departure_time' => '12:00'], 'Late Departure', 'checkedOut');
    boardUnit($property, $earlyArrival, ['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-13', 'arrival_time' => '12:30'], 'Early Arrival');
    boardUnit($property, $earlyDeparture, ['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12', 'departure_time' => '09:00'], 'Early Departure', 'checkedOut');
    boardUnit($property, $inHouse, ['arrival_date' => '2026-10-11', 'departure_date' => '2026-10-14'], 'Staying Guest', 'checkedIn');

    $this->actingAs($owner)
        ->get(route('housekeeping.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rooms.0.number', '105')
            ->where('rooms.1.number', '103')
            ->where('rooms.2.number', '104')
            ->where('rooms.3.number', '102')
            ->where('rooms.4.number', '101')
            ->where('rooms.5.number', '106'));

    expect($idle->status->status)->toBe(HousekeepingStatus::Inspected);
});

test('an open whole-room block puts a room out of service and a bed block only adds a note', function () {
    $owner = User::factory()->owner()->create();
    $property = boardProperty($owner);
    $blocked = boardRoom($property, '201', HousekeepingStatus::Dirty);
    $dorm = Room::factory()->for($property)->create([
        'number' => '12',
        'room_type_id' => RoomType::factory()->for($property)->bed()->create()->id,
    ]);
    $bed = Bed::factory()->for($dorm)->create(['label' => 'A3']);
    RoomBlock::factory()->for($blocked)->create(['property_id' => $property->id, 'starts_on' => '2026-10-11', 'ends_on' => '2026-10-18', 'notes' => 'Aircon']);
    RoomBlock::factory()->for($dorm)->create(['property_id' => $property->id, 'bed_id' => $bed->id, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-13']);
    RoomBlock::factory()->for($blocked)->completed()->create(['property_id' => $property->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-30']);

    $this->actingAs($owner)
        ->get(route('housekeeping.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rooms.0.number', '12')
            ->where('rooms.0.outOfService', null)
            ->where('rooms.0.bedBlocks.0.bedLabel', 'A3')
            ->where('rooms.1.number', '201')
            ->where('rooms.1.status.value', 'out_of_service')
            ->where('rooms.1.status.tone', 'danger')
            ->where('rooms.1.outOfService.endsOn', '2026-10-18')
            ->where('rooms.1.outOfService.notes', 'Aircon')
            ->where('rooms.1.moves', []));
});

test('housekeepers cannot move a room to Inspected, front desk can', function () {
    $housekeeper = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();
    $property = boardProperty($housekeeper);
    boardRoom($property, '101', HousekeepingStatus::Clean);

    $this->actingAs($housekeeper)
        ->get(route('housekeeping.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->has('rooms.0.moves', 1)->where('rooms.0.moves.0.value', 'dirty')->where('can.update', true));

    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $property->id]);

    $this->actingAs($frontDesk)
        ->get(route('housekeeping.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->has('rooms.0.moves', 2)->where('rooms.0.moves.1.value', 'inspected'));
});

test('staff without housekeeping.view cannot open the board', function () {
    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create();

    $this->actingAs($finance)->get(route('housekeeping.index', $finance->property->code))->assertForbidden();
});

test('a housekeeper marks a room clean and the change is logged', function () {
    $housekeeper = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();
    $property = boardProperty($housekeeper);
    $room = boardRoom($property, '101', HousekeepingStatus::Dirty);

    $this->actingAs($housekeeper)
        ->put(route('housekeeping.rooms.status', [$property->code, $room]), ['status' => 'clean'])
        ->assertRedirect(route('housekeeping.index', $property->code));

    $status = $room->status->fresh();

    expect($status->status)->toBe(HousekeepingStatus::Clean)
        ->and($status->last_changed_by)->toBe($housekeeper->id)
        ->and($status->last_changed_at)->not->toBeNull();

    $activity = Activity::query()->where('event', 'room_status.changed')->sole();
    expect($activity->properties['from'])->toBe('dirty')->and($activity->properties['to'])->toBe('clean');
});

test('the same status again changes nothing and logs nothing', function () {
    $owner = User::factory()->owner()->create();
    $property = boardProperty($owner);
    $room = boardRoom($property, '101', HousekeepingStatus::Clean);

    $this->actingAs($owner)->put(route('housekeeping.rooms.status', [$property->code, $room]), ['status' => 'clean'])->assertRedirect();

    expect(Activity::query()->where('event', 'room_status.changed')->count())->toBe(0);
});

test('a room cannot skip a step, and a room out of service refuses every change', function () {
    $owner = User::factory()->owner()->create();
    $property = boardProperty($owner);
    $dirty = boardRoom($property, '101', HousekeepingStatus::Dirty);
    $blocked = boardRoom($property, '102', HousekeepingStatus::Dirty);
    RoomBlock::factory()->for($blocked)->create(['property_id' => $property->id, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-14']);

    $this->actingAs($owner)->put(route('housekeeping.rooms.status', [$property->code, $dirty]), ['status' => 'inspected'])->assertRedirect();
    $this->actingAs($owner)->put(route('housekeeping.rooms.status', [$property->code, $blocked]), ['status' => 'clean'])->assertRedirect();

    expect($dirty->status->fresh()->status)->toBe(HousekeepingStatus::Dirty)
        ->and($blocked->status->fresh()->status)->toBe(HousekeepingStatus::Dirty)
        ->and(Activity::query()->where('event', 'room_status.changed')->count())->toBe(0);
});

test('only users with housekeeping.inspect can inspect', function () {
    $housekeeper = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();
    $property = boardProperty($housekeeper);
    $room = boardRoom($property, '101', HousekeepingStatus::Clean);

    $this->actingAs($housekeeper)->put(route('housekeeping.rooms.status', [$property->code, $room]), ['status' => 'inspected'])->assertForbidden();

    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create(['property_id' => $property->id]);

    $this->actingAs($manager)->put(route('housekeeping.rooms.status', [$property->code, $room]), ['status' => 'inspected'])->assertRedirect();

    expect($room->status->fresh()->status)->toBe(HousekeepingStatus::Inspected);
});

test('a room is assigned to staff who can update housekeeping, and cleared', function () {
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create();
    $property = boardProperty($manager);
    $room = boardRoom($property, '101');
    $housekeeper = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $property->id]);
    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create(['property_id' => $property->id]);
    $stranger = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();

    $url = route('housekeeping.rooms.assignee', [$property->code, $room]);

    $this->actingAs($manager)->put($url, ['assigned_to' => $finance->id])->assertSessionHasErrors('assigned_to');
    $this->actingAs($manager)->put($url, ['assigned_to' => $stranger->id])->assertSessionHasErrors('assigned_to');
    $this->actingAs($manager)->put($url, ['assigned_to' => $housekeeper->id])->assertSessionHasNoErrors();

    expect($room->status->fresh()->assigned_to)->toBe($housekeeper->id);

    $this->actingAs($manager)->put($url, ['assigned_to' => null])->assertSessionHasNoErrors();

    expect($room->status->fresh()->assigned_to)->toBeNull();
});

test('priority and do-not-disturb are set for the board date and cleared', function () {
    $owner = User::factory()->owner()->create();
    $property = boardProperty($owner);
    $room = boardRoom($property, '101');

    $this->actingAs($owner)->put(route('housekeeping.rooms.priority', [$property->code, $room]), ['priority' => true, 'note' => 'VIP arrival'])->assertSessionHasNoErrors();
    $this->actingAs($owner)->put(route('housekeeping.rooms.dnd', [$property->code, $room]), ['dnd' => true])->assertSessionHasNoErrors();

    $status = $room->status->fresh();
    expect($status->is_priority)->toBeTrue()
        ->and($status->priority_note)->toBe('VIP arrival')
        ->and($status->do_not_disturb_on->toDateString())->toBe('2026-10-12');

    $this->actingAs($owner)->put(route('housekeeping.rooms.priority', [$property->code, $room]), ['priority' => false])->assertSessionHasNoErrors();
    $this->actingAs($owner)->put(route('housekeeping.rooms.dnd', [$property->code, $room]), ['dnd' => false])->assertSessionHasNoErrors();

    $status = $room->status->fresh();
    expect($status->is_priority)->toBeFalse()
        ->and($status->priority_note)->toBeNull()
        ->and($status->do_not_disturb_on)->toBeNull();
});

test('a room of another property is not found', function () {
    $owner = User::factory()->owner()->create();
    $property = boardProperty($owner);
    $other = Room::factory()->create();

    $this->actingAs($owner)->put(route('housekeeping.rooms.status', [$property->code, $other]), ['status' => 'dirty'])->assertNotFound();
});

test('the board runs the same number of queries for few rooms and many', function () {
    $owner = User::factory()->owner()->create();
    $property = boardProperty($owner);
    $count = function () use ($owner, $property): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->get(route('housekeeping.index', $property->code))->assertOk();

        return count(DB::getQueryLog());
    };

    foreach (['101', '102'] as $number) {
        $room = boardRoom($property, $number, HousekeepingStatus::Dirty);
        boardUnit($property, $room, ['arrival_date' => '2026-10-11', 'departure_date' => '2026-10-14'], state: 'checkedIn');
    }

    $count();
    $few = $count();

    foreach (['103', '104', '105', '106'] as $number) {
        $room = boardRoom($property, $number, HousekeepingStatus::Dirty);
        boardUnit($property, $room, ['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-13']);
    }

    expect($count())->toBe($few);
});

test('a remark is trimmed, saved, logged and kept when the room turns clean', function () {
    $housekeeper = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();
    $property = boardProperty($housekeeper);
    $room = boardRoom($property, '101', HousekeepingStatus::Dirty);
    $url = route('housekeeping.rooms.remark', [$property->code, $room]);

    $this->actingAs($housekeeper)->put($url, ['remark' => '  Extra towels  '])->assertSessionHasNoErrors();

    expect($room->status->fresh()->remark)->toBe('Extra towels');

    $activity = Activity::query()->where('event', 'room_status.remark_set')->sole();
    expect($activity->properties['from'])->toBeNull()->and($activity->properties['to'])->toBe('Extra towels');

    $this->put(route('housekeeping.rooms.status', [$property->code, $room]), ['status' => 'clean'])->assertRedirect();

    expect($room->status->fresh()->remark)->toBe('Extra towels');

    $this->put($url, ['remark' => 'Extra towels'])->assertSessionHasNoErrors();
    expect(Activity::query()->where('event', 'room_status.remark_set')->count())->toBe(1);

    $this->put($url, ['remark' => '   '])->assertSessionHasNoErrors();
    expect($room->status->fresh()->remark)->toBeNull();

    $this->put($url, ['remark' => str_repeat('a', 501)])->assertSessionHasErrors('remark');
});

test('the board sends the remark and the needs maintenance flag', function () {
    $owner = User::factory()->owner()->create();
    $property = boardProperty($owner);
    $room = boardRoom($property, '101');
    $room->status->update(['remark' => 'Key at desk', 'needs_maintenance' => true]);

    $this->actingAs($owner)
        ->get(route('housekeeping.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rooms.0.remark', 'Key at desk')
            ->where('rooms.0.needsMaintenance', true)
            ->where('rooms.0.canDismissMaintenance', true));

    $housekeeper = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $property->id]);

    $this->actingAs($housekeeper)
        ->get(route('housekeeping.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rooms.0.needsMaintenance', true)
            ->where('rooms.0.canDismissMaintenance', false));
});

test('housekeeping raises needs maintenance but only rooms.block dismisses it', function () {
    $housekeeper = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();
    $property = boardProperty($housekeeper);
    $room = boardRoom($property, '101');
    $url = route('housekeeping.rooms.maintenance', [$property->code, $room]);

    $this->actingAs($housekeeper)->put($url, ['needs_maintenance' => true])->assertSessionHasNoErrors();

    expect($room->status->fresh()->needs_maintenance)->toBeTrue()
        ->and(Activity::query()->where('event', 'room_status.maintenance_flagged')->count())->toBe(1);

    $this->put($url, ['needs_maintenance' => false])->assertForbidden();

    expect($room->status->fresh()->needs_maintenance)->toBeTrue();

    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $property->id]);

    $this->actingAs($frontDesk)->put($url, ['needs_maintenance' => false])->assertSessionHasNoErrors();

    expect($room->status->fresh()->needs_maintenance)->toBeFalse()
        ->and(Activity::query()->where('event', 'room_status.maintenance_cleared')->count())->toBe(1);
});

test('blocking a room clears its needs maintenance flag', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $property = boardProperty($frontDesk);
    $room = boardRoom($property, '101');
    $room->status->update(['needs_maintenance' => true]);

    $this->actingAs($frontDesk)
        ->post(route('front-desk.room-blocks.store', $property->code), [
            'room_id' => $room->id,
            'reason' => 'maintenance',
            'starts_on' => '2026-10-12',
            'ends_on' => '2026-10-14',
            'notes' => 'Aircon',
        ])
        ->assertSessionHasNoErrors();

    expect(RoomBlock::query()->where('room_id', $room->id)->count())->toBe(1)
        ->and($room->status->fresh()->needs_maintenance)->toBeFalse();
});
