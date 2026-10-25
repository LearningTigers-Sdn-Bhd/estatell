<?php

use App\Actions\FrontDesk\AutoAssignRooms;
use App\Actions\FrontDesk\CheckInUnit;
use App\Actions\FrontDesk\MoveUnit;
use App\Actions\Reservations\AssignmentResult;
use App\Enums\DefaultRoleEnum;
use App\Enums\HousekeepingStatus;
use App\Enums\UnitAction;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\RoomStatus;
use App\Models\RoomType;
use App\Models\UnitNight;
use App\Models\User;
use Illuminate\Database\QueryException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * A live property with an open business date (today), a plan sold on a Standard room type (rooms 101 to 103) and a
 * Deluxe room type (room 201), and a Confirmed unit that arrives today for three nights in room 101.
 *
 * @param  array<string, mixed>  $unit
 * @return array{user: User, property: Property, standard: RoomType, deluxe: RoomType, rooms: array<string, Room>, plan: RatePlan, reservation: Reservation, unit: ReservationUnit}
 */
function moveSetup(array $unit = [], bool $linkDeluxe = true): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);

    $standard = RoomType::factory()->for($property)->create(['name' => 'Standard']);
    $deluxe = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    $rooms = [];

    foreach ([['101', $standard], ['102', $standard], ['103', $standard], ['201', $deluxe]] as $position => [$number, $type]) {
        $rooms[$number] = Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => $number, 'position' => $position]);
    }

    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($linkDeluxe ? [$standard->id, $deluxe->id] : [$standard->id]);

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->create([
        'room_type_id' => $standard->id,
        'rate_plan_id' => $plan->id,
        'arrival_date' => today()->toDateString(),
        'departure_date' => today()->addDays(3)->toDateString(),
        ...$unit,
    ]);
    $unit->nights()->update(['room_id' => $rooms['101']->id]);

    return compact('user', 'property', 'standard', 'deluxe', 'rooms', 'plan', 'reservation', 'unit');
}

/**
 * A second Confirmed unit of the property in the given room for the same three nights.
 *
 * @param  array<string, mixed>  $setup
 */
function moveOtherUnit(array $setup, Room $room, string $arrival = '+0 days', string $departure = '+3 days'): ReservationUnit
{
    $other = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $setup['property']->id]))->withNights()->create([
        'room_type_id' => $room->room_type_id,
        'rate_plan_id' => $setup['plan']->id,
        'arrival_date' => today()->modify($arrival)->toDateString(),
        'departure_date' => today()->modify($departure)->toDateString(),
    ]);
    $other->nights()->update(['room_id' => $room->id]);

    return $other;
}

/** @return list<string|null> */
function moveRooms(ReservationUnit $unit): array
{
    return $unit->nights()->orderBy('date')->with('room')->get()->map(fn (UnitNight $night): ?string => $night->room?->number)->all();
}

function moveUnit(array $setup, Room|Bed $target, string $from = '+0 days', string $reason = 'Guest asked'): AssignmentResult
{
    return app(MoveUnit::class)($setup['user'], $setup['unit'], $target, today()->modify($from), $reason);
}

test('a move changes the room from the chosen night and keeps the earlier nights', function () {
    $setup = moveSetup();

    $result = moveUnit($setup, $setup['rooms']['102'], '+1 day');

    expect($result->isRefused())->toBeFalse()
        ->and(moveRooms($setup['unit']))->toBe(['101', '102', '102']);

    $log = Activity::query()->where('event', 'reservation.unit_moved')->sole();

    expect($log->properties['from_night'])->toBe(today()->addDay()->toDateString())
        ->and($log->properties['room_id'])->toBe($setup['rooms']['102']->id)
        ->and($log->properties['previous']['room_ids'])->toBe([$setup['rooms']['101']->id])
        ->and($log->properties['reason'])->toBe('Guest asked');
});

test('a move from the arrival night moves the whole stay', function () {
    $setup = moveSetup();

    moveUnit($setup, $setup['rooms']['103']);

    expect(moveRooms($setup['unit']))->toBe(['103', '103', '103']);
});

test('a move is refused and writes nothing when a rule fails', function (string $case, string $refusal) {
    $setup = moveSetup(linkDeluxe: $case !== 'room type not linked');
    $target = $setup['rooms']['102'];
    $from = '+0 days';
    $reason = 'Guest asked';

    match ($case) {
        'unit closed' => $setup['unit']->forceFill(['status' => UnitStatus::Cancelled])->save(),
        'no reason' => $reason = '  ',
        'night outside the stay' => $from = '+3 days',
        'night in the past' => $from = '-1 day',
        'room not free' => moveOtherUnit($setup, $target, '+1 day', '+2 days'),
        'already there' => $target = $setup['rooms']['101'],
        'room type not linked' => $target = $setup['rooms']['201'],
        'mid-stay type change' => [$target, $from] = [$setup['rooms']['201'], '+1 day'],
    };

    $result = moveUnit($setup, $target, $from, $reason);

    expect($result->refusal)->toBe($refusal)
        ->and(moveRooms($setup['unit']))->toBe(['101', '101', '101'])
        ->and(Activity::query()->where('event', 'reservation.unit_moved')->count())->toBe(0);
})->with([
    ['unit closed', AssignmentResult::REFUSAL_UNIT_NOT_OPEN],
    ['no reason', AssignmentResult::REFUSAL_REASON_REQUIRED],
    ['night outside the stay', AssignmentResult::REFUSAL_NIGHT_INVALID],
    ['night in the past', AssignmentResult::REFUSAL_NIGHT_INVALID],
    ['room not free', AssignmentResult::REFUSAL_ROOM_NOT_FREE],
    ['already there', AssignmentResult::REFUSAL_UNCHANGED],
    ['room type not linked', AssignmentResult::REFUSAL_ROOM_TYPE_NOT_ALLOWED],
    ['mid-stay type change', AssignmentResult::REFUSAL_WHOLE_STAY_REQUIRED],
]);

test('a move cannot start before the business date', function () {
    $setup = moveSetup(['arrival_date' => today()->subDay()->toDateString(), 'status' => UnitStatus::CheckedIn, 'checked_in_at' => now()->subDay()]);

    expect(moveUnit($setup, $setup['rooms']['102'], '-1 day')->refusal)->toBe(AssignmentResult::REFUSAL_NIGHT_IN_PAST);
});

test('a move to a linked room type changes the unit room type and keeps the prices', function () {
    $setup = moveSetup();
    $before = $setup['unit']->nights()->sum('amount');

    expect(moveUnit($setup, $setup['rooms']['201'])->isRefused())->toBeFalse()
        ->and($setup['unit']->fresh()->room_type_id)->toBe($setup['deluxe']->id)
        ->and(moveRooms($setup['unit']))->toBe(['201', '201', '201'])
        ->and($setup['unit']->nights()->sum('amount'))->toEqual($before);
});

test('a room unit cannot move to a bed room type', function () {
    $setup = moveSetup();
    $dorm = RoomType::factory()->bed()->for($setup['property'])->create();
    $dormRoom = Room::factory()->for($setup['property'])->create(['room_type_id' => $dorm->id, 'number' => 'D1']);
    $bed = Bed::factory()->create(['room_id' => $dormRoom->id, 'label' => 'Bed 1']);
    $setup['plan']->roomTypes()->attach($dorm);

    expect(moveUnit($setup, $bed)->refusal)->toBe(AssignmentResult::REFUSAL_ROOM_TYPE_NOT_ALLOWED);
});

test('a bed unit moves to a free bed', function () {
    $setup = moveSetup();
    $dorm = RoomType::factory()->bed()->for($setup['property'])->create();
    $dormRoom = Room::factory()->for($setup['property'])->create(['room_type_id' => $dorm->id, 'number' => 'D1']);
    $beds = [Bed::factory()->create(['room_id' => $dormRoom->id, 'label' => 'Bed 1']), Bed::factory()->create(['room_id' => $dormRoom->id, 'label' => 'Bed 2'])];
    $setup['plan']->roomTypes()->attach($dorm);
    $unit = ReservationUnit::factory()->for($setup['reservation'])->withNights()->create([
        'room_type_id' => $dorm->id,
        'rate_plan_id' => $setup['plan']->id,
        'arrival_date' => today()->toDateString(),
        'departure_date' => today()->addDays(2)->toDateString(),
    ]);
    $unit->nights()->update(['bed_id' => $beds[0]->id]);

    $result = app(MoveUnit::class)($setup['user'], $unit, $beds[1], today(), 'Snoring');

    expect($result->isRefused())->toBeFalse()
        ->and($unit->nights()->pluck('bed_id')->unique()->all())->toBe([$beds[1]->id])
        ->and($unit->nights()->pluck('room_id')->filter()->all())->toBe([]);
});

test('the database refuses a room on two units for the same night', function () {
    $setup = moveSetup();
    $other = moveOtherUnit($setup, $setup['rooms']['102']);

    expect(fn () => $other->nights()->update(['room_id' => $setup['rooms']['101']->id]))->toThrow(QueryException::class);
});

test('a cancelled unit frees its room for another unit', function () {
    $setup = moveSetup();
    $setup['unit']->nights()->update(['room_id' => null]);
    $setup['unit']->forceFill(['status' => UnitStatus::Cancelled])->save();
    $other = moveOtherUnit($setup, $setup['rooms']['101']);

    expect(moveRooms($other))->toBe(['101', '101', '101']);
});

test('auto assign picks the first free room by group and position', function () {
    $setup = moveSetup();
    $setup['unit']->nights()->update(['room_id' => null]);
    $groupB = RoomGroup::factory()->for($setup['property'])->create(['position' => 2]);
    $groupA = RoomGroup::factory()->for($setup['property'])->create(['position' => 1]);
    $setup['rooms']['101']->update(['room_group_id' => $groupB->id]);
    $setup['rooms']['102']->update(['room_group_id' => $groupB->id]);
    $setup['rooms']['103']->update(['room_group_id' => $groupA->id]);

    $result = app(AutoAssignRooms::class)($setup['user'], $setup['unit']);

    expect($result->isRefused())->toBeFalse()
        ->and(moveRooms($setup['unit']))->toBe(['103', '103', '103']);
});

test('auto assign prefers a clean room and skips a taken one', function () {
    $setup = moveSetup();
    $setup['unit']->nights()->update(['room_id' => null]);
    RoomStatus::query()->where('room_id', $setup['rooms']['101']->id)->update(['status' => HousekeepingStatus::Dirty]);
    moveOtherUnit($setup, $setup['rooms']['102']);

    app(AutoAssignRooms::class)($setup['user'], $setup['unit']);

    expect(moveRooms($setup['unit']))->toBe(['103', '103', '103']);
});

test('auto assign reports when no room is free', function () {
    $setup = moveSetup();
    $setup['unit']->nights()->update(['room_id' => null]);

    foreach (['101', '102', '103'] as $number) {
        moveOtherUnit($setup, $setup['rooms'][$number]);
    }

    expect(app(AutoAssignRooms::class)($setup['user'], $setup['unit'])->refusal)->toBe(AssignmentResult::REFUSAL_NO_FREE_UNIT);
});

test('check-in gives a unit without a room one when auto assign is on', function (bool $autoAssign, bool $checkedIn) {
    $setup = moveSetup();
    $setup['unit']->nights()->update(['room_id' => null]);
    $setup['property']->settings->update(['auto_assign_rooms' => $autoAssign]);

    $result = app(CheckInUnit::class)($setup['user'], $setup['unit'], true);

    expect($result->isRefused())->toBe(! $checkedIn)
        ->and($setup['unit']->fresh()->status)->toBe($checkedIn ? UnitStatus::CheckedIn : UnitStatus::Confirmed);
})->with([[true, true], [false, false]]);

test('the unit menu lists Move room for an open unit and not for a closed one', function () {
    $setup = moveSetup();

    expect(UnitAction::MoveRoom->isOpenFor(UnitStatus::Confirmed))->toBeTrue()
        ->and(UnitAction::MoveRoom->isOpenFor(UnitStatus::CheckedIn))->toBeTrue()
        ->and(UnitAction::MoveRoom->isOpenFor(UnitStatus::CheckedOut))->toBeFalse()
        ->and(UnitAction::MoveRoom->isOpenFor(UnitStatus::Cancelled))->toBeFalse();
});

test('the move route moves the unit and needs reservations.manage', function () {
    $setup = moveSetup();
    $url = route('reservations.units.move', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);
    $payload = ['from_night' => today()->toDateString(), 'room_id' => $setup['rooms']['102']->id, 'reason' => 'Noise'];

    $this->actingAs(User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]))
        ->post($url, $payload)->assertForbidden();

    $this->actingAs($setup['user'])->post($url, $payload)->assertRedirect();

    expect(moveRooms($setup['unit']))->toBe(['102', '102', '102']);

    $this->actingAs($setup['user'])->post($url, [...$payload, 'room_id' => $setup['rooms']['101']->id, 'reason' => ''])->assertSessionHasErrors('reason');
});

test('the move route refuses a room of another property', function () {
    $setup = moveSetup();
    $foreign = Room::factory()->create();
    $url = route('reservations.units.move', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);

    $this->actingAs($setup['user'])
        ->post($url, ['from_night' => today()->toDateString(), 'room_id' => $foreign->id, 'reason' => 'x'])
        ->assertSessionHasErrors('room_id');

    expect(moveRooms($setup['unit']))->toBe(['101', '101', '101']);
});

test('the move preview lists the free rooms of the chosen room type from the night', function () {
    $setup = moveSetup();
    moveOtherUnit($setup, $setup['rooms']['102'], '+1 day', '+2 days');
    $url = route('reservations.units.move-preview', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);

    $own = $this->actingAs($setup['user'])->postJson($url, ['from_night' => today()->toDateString()])->assertOk()->json();

    expect($own['current'])->toBe('101')
        ->and(array_column($own['roomTypes'], 'name'))->toBe(['Deluxe', 'Standard'])
        ->and($own['roomTypeId'])->toBe($setup['standard']->id)
        ->and(array_column($own['units'], 'label'))->toBe(['101', '103']);

    $deluxe = $this->actingAs($setup['user'])->postJson($url, ['from_night' => today()->toDateString(), 'room_type_id' => $setup['deluxe']->id])->json();

    expect(array_column($deluxe['units'], 'label'))->toBe(['201']);
});

test('the move preview lists every row a Timeline drag may drop on, with the night each is free from', function () {
    $setup = moveSetup();
    moveOtherUnit($setup, $setup['rooms']['102'], '+0 days', '+1 day');
    $url = route('reservations.units.move-preview', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);

    $targets = $this->actingAs($setup['user'])->postJson($url, ['from_night' => today()->toDateString(), 'targets' => true])->assertOk()->json('targets');

    expect(array_column($targets, 'freeFrom', 'rowKey'))->toBe([
        'room-'.$setup['rooms']['101']->id => today()->toDateString(),
        'room-'.$setup['rooms']['102']->id => today()->addDay()->toDateString(),
        'room-'.$setup['rooms']['103']->id => today()->toDateString(),
        'room-'.$setup['rooms']['201']->id => today()->toDateString(),
    ]);

    $this->actingAs($setup['user'])->postJson($url, ['from_night' => today()->toDateString()])->assertJsonPath('targets', []);
});

test('the drag targets leave out a room taken on the last night and another room type unless the move is the whole stay', function () {
    $setup = moveSetup();
    moveOtherUnit($setup, $setup['rooms']['103'], '+2 days', '+3 days');
    moveOtherUnit($setup, $setup['rooms']['201'], '+1 day', '+2 days');
    $url = route('reservations.units.move-preview', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);

    $whole = $this->actingAs($setup['user'])->postJson($url, ['from_night' => today()->toDateString(), 'targets' => true])->json('targets');

    expect(array_column($whole, 'rowKey'))->toBe(['room-'.$setup['rooms']['101']->id, 'room-'.$setup['rooms']['102']->id]);

    $later = $this->actingAs($setup['user'])->postJson($url, ['from_night' => today()->addDay()->toDateString(), 'targets' => true])->json('targets');

    expect(array_column($later, 'roomTypeId'))->each->toBe($setup['standard']->id)
        ->and(array_column($later, 'rowKey'))->not->toContain('room-'.$setup['rooms']['201']->id);
});

test('a first assignment needs no reason but a real move still does', function () {
    $setup = moveSetup();
    $setup['unit']->nights()->update(['room_id' => null]);

    $result = moveUnit($setup, $setup['rooms']['102'], '+0 days', '');

    expect($result->isRefused())->toBeFalse()
        ->and(moveRooms($setup['unit']))->toBe(['102', '102', '102'])
        ->and(Activity::query()->where('event', 'reservation.unit_moved')->sole()->properties['reason'])->toBeNull();

    expect(moveUnit($setup, $setup['rooms']['103'], '+0 days', '')->refusal)->toBe(AssignmentResult::REFUSAL_REASON_REQUIRED);
});

test('the move preview says whether a reason is needed', function () {
    $setup = moveSetup();
    $url = route('reservations.units.move-preview', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);
    $query = ['from_night' => today()->toDateString()];

    $this->actingAs($setup['user'])->postJson($url, $query)->assertJsonPath('reasonRequired', true);

    $setup['unit']->nights()->update(['room_id' => null]);

    $this->actingAs($setup['user'])->postJson($url, $query)->assertJsonPath('reasonRequired', false);
});

test('the move route takes a first assignment without a reason', function () {
    $setup = moveSetup();
    $setup['unit']->nights()->update(['room_id' => null]);
    $url = route('reservations.units.move', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);

    $this->actingAs($setup['user'])->post($url, ['from_night' => today()->toDateString(), 'room_id' => $setup['rooms']['102']->id])
        ->assertSessionHasNoErrors();

    expect(moveRooms($setup['unit']))->toBe(['102', '102', '102']);
});
