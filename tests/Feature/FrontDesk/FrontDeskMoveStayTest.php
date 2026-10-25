<?php

use App\Actions\FrontDesk\MoveUnitStay;
use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Reservations\AssignmentResult;
use App\Enums\DefaultRoleEnum;
use App\Enums\UnitStatus;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/RoomMoveSetup.php';

/**
 * `moveSetup()` (a Confirmed unit in room 101 for today and the next two nights) with a 100.00 rate for both room
 * types, from yesterday for twelve nights, so a shifted stay can be priced.
 *
 * @param  array<string, mixed>  $unit
 * @return array<string, mixed>
 */
function moveStaySetup(array $unit = [], bool $linkDeluxe = true): array
{
    $setup = moveSetup($unit, $linkDeluxe);

    foreach ([$setup['standard'], $setup['deluxe']] as $roomType) {
        for ($day = -1; $day < 11; $day++) {
            Rate::factory()->create(['rate_plan_id' => $setup['plan']->id, 'room_type_id' => $roomType->id, 'date' => today()->addDays($day)->toDateString(), 'amount' => '100.00']);
        }
    }

    return $setup;
}

/**
 * @param  array<string, mixed>  $setup
 */
function moveStay(array $setup, int $shift, Room $target, ?string $reason = null, ?string $override = null): AssignmentResult
{
    $unit = $setup['unit'];

    return app(MoveUnitStay::class)($setup['user'], $unit, $unit->arrival_date->addDays($shift), $unit->departure_date->addDays($shift), $target, $reason, $override);
}

/**
 * @param  array<string, mixed>  $setup
 * @return array{dates: string, rooms: list<string|null>, activity: int}
 */
function moveStayState(array $setup): array
{
    $unit = $setup['unit']->refresh();

    return [
        'dates' => $unit->arrival_date->toDateString().' '.$unit->departure_date->toDateString(),
        'rooms' => moveRooms($unit),
        'activity' => Activity::query()->count(),
    ];
}

test('a shift and a room change save both, with one log row for each', function () {
    $setup = moveStaySetup();

    $result = moveStay($setup, 1, $setup['rooms']['102'], 'Guest asked for a later arrival');

    $unit = $setup['unit']->refresh();

    expect($result->isRefused())->toBeFalse()
        ->and($unit->arrival_date->toDateString())->toBe(today()->addDay()->toDateString())
        ->and($unit->departure_date->toDateString())->toBe(today()->addDays(4)->toDateString())
        ->and(moveRooms($unit))->toBe(['102', '102', '102'])
        ->and(Activity::query()->where('event', 'reservation.unit_dates_changed')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'reservation.unit_moved')->sole()->properties['reason'])->toBe('Guest asked for a later arrival')
        ->and($unit->nights()->orderBy('date')->first()->date->toDateString())->toBe(today()->addDay()->toDateString());
});

test('a sideways shift that keeps the room needs no reason', function () {
    $setup = moveStaySetup();

    $result = moveStay($setup, 1, $setup['rooms']['101']);

    expect($result->isRefused())->toBeFalse()
        ->and(moveRooms($setup['unit']->refresh()))->toBe(['101', '101', '101'])
        ->and(Activity::query()->where('event', 'reservation.unit_dates_changed')->count())->toBe(1);
});

test('a shift to a room that is taken on a new night changes nothing', function () {
    $setup = moveStaySetup();
    moveOtherUnit($setup, $setup['rooms']['102'], '+3 days', '+4 days');
    $before = moveStayState($setup);

    $result = moveStay($setup, 1, $setup['rooms']['102'], 'Noise');

    expect($result->refusal)->toBe(AssignmentResult::REFUSAL_ROOM_NOT_FREE)
        ->and($result->date?->toDateString())->toBe(today()->addDays(3)->toDateString())
        ->and(moveStayState($setup))->toBe($before);
});

test('a room change without a reason rolls the date change back', function () {
    $setup = moveStaySetup();
    $before = moveStayState($setup);

    $result = moveStay($setup, 1, $setup['rooms']['102'], '  ');

    expect($result->refusal)->toBe(AssignmentResult::REFUSAL_REASON_REQUIRED)
        ->and(moveStayState($setup))->toBe($before);
});

test('refused dates change nothing', function (string $case, string $refusal) {
    $setup = moveStaySetup();

    if ($case === 'restricted') {
        Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', today()->addDays(3))->update(['stop_sell' => true]);
    }

    if ($case === 'sold out') {
        ReservationUnit::factory()->count(3)->withNights()->for(Reservation::factory()->create(['property_id' => $setup['property']->id]))->create([
            'room_type_id' => $setup['standard']->id,
            'rate_plan_id' => $setup['plan']->id,
            'arrival_date' => today()->addDays(3)->toDateString(),
            'departure_date' => today()->addDays(4)->toDateString(),
        ]);
    }

    $before = moveStayState($setup);

    $result = $case === 'in the past'
        ? moveStay($setup, -1, $setup['rooms']['102'], 'Earlier')
        : moveStay($setup, 1, $setup['rooms']['102'], 'Later');

    expect($result->refusal)->toBe($refusal)
        ->and(moveStayState($setup))->toBe($before);
})->with([
    ['restricted', UnitStayResult::REFUSAL_RESTRICTED],
    ['sold out', UnitStayResult::REFUSAL_NOT_AVAILABLE],
    ['in the past', UnitStayResult::REFUSAL_ARRIVAL_IN_PAST],
]);

test('a restriction passes with an override reason', function () {
    $setup = moveStaySetup();
    Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', today()->addDays(3))->update(['stop_sell' => true]);

    $result = moveStay($setup, 1, $setup['rooms']['102'], 'Later', 'Manager agreed');

    expect($result->isRefused())->toBeFalse()
        ->and(moveRooms($setup['unit']->refresh()))->toBe(['102', '102', '102']);
});

test('only a Confirmed unit can move its dates and room together', function (UnitStatus $status) {
    $setup = moveStaySetup(['status' => $status, 'checked_in_at' => now()]);
    $before = moveStayState($setup);

    $result = moveStay($setup, 1, $setup['rooms']['102'], 'Later');

    expect($result->refusal)->toBe(UnitStayResult::REFUSAL_INVALID_STATUS)
        ->and(moveStayState($setup))->toBe($before);
})->with([UnitStatus::CheckedIn, UnitStatus::Cancelled]);

test('the move-stay route saves and needs reservations.manage', function () {
    $setup = moveStaySetup();
    $url = route('reservations.units.move-stay', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);
    $payload = ['arrival_date' => today()->addDay()->toDateString(), 'departure_date' => today()->addDays(4)->toDateString(), 'room_id' => $setup['rooms']['102']->id, 'reason' => 'Later'];

    $this->actingAs(User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]))
        ->post($url, $payload)->assertForbidden();

    $this->actingAs($setup['user'])->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();

    expect(moveRooms($setup['unit']->refresh()))->toBe(['102', '102', '102'])
        ->and($setup['unit']->arrival_date->toDateString())->toBe(today()->addDay()->toDateString());
});

test('the move-stay route shows a refusal on its field and writes nothing', function () {
    $setup = moveStaySetup();
    moveOtherUnit($setup, $setup['rooms']['102'], '+3 days', '+4 days');
    $url = route('reservations.units.move-stay', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);
    $payload = ['arrival_date' => today()->addDay()->toDateString(), 'departure_date' => today()->addDays(4)->toDateString(), 'room_id' => $setup['rooms']['102']->id, 'reason' => 'Later'];
    $before = moveStayState($setup);

    $this->actingAs($setup['user'])->post($url, $payload)->assertSessionHasErrors('room_id');
    $this->actingAs($setup['user'])->post($url, [...$payload, 'reason' => ''])->assertSessionHasErrors('reason');

    expect(moveStayState($setup))->toBe($before);
});

test('the move-stay route refuses a room of another property', function () {
    $setup = moveStaySetup();
    $foreign = Room::factory()->create();
    $url = route('reservations.units.move-stay', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);
    $before = moveStayState($setup);

    $this->actingAs($setup['user'])
        ->post($url, ['arrival_date' => today()->addDay()->toDateString(), 'departure_date' => today()->addDays(4)->toDateString(), 'room_id' => $foreign->id, 'reason' => 'x'])
        ->assertSessionHasErrors('room_id');

    expect(moveStayState($setup))->toBe($before);
});

test('the move-stay preview reports the target, the room type, the restriction and the reason', function () {
    $setup = moveStaySetup();
    moveOtherUnit($setup, $setup['rooms']['102'], '+3 days', '+4 days');
    Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', today()->addDays(3))->update(['stop_sell' => true]);
    $url = route('reservations.units.move-stay-preview', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);
    $dates = ['arrival_date' => today()->addDay()->toDateString(), 'departure_date' => today()->addDays(4)->toDateString()];

    $taken = $this->actingAs($setup['user'])->postJson($url, [...$dates, 'room_id' => $setup['rooms']['102']->id])->assertOk()->json();

    expect($taken['targetFree'])->toBeFalse()
        ->and($taken['firstTakenNight'])->toBe(today()->addDays(3)->toDateString())
        ->and($taken['roomTypeAllowed'])->toBeTrue()
        ->and($taken['reasonRequired'])->toBeTrue()
        ->and($taken['dates']['restricted'])->toBeTrue()
        ->and($taken['dates']['nights'])->toBe(3)
        ->and($taken['error'])->toBeNull();

    $same = $this->actingAs($setup['user'])->postJson($url, [...$dates, 'room_id' => $setup['rooms']['101']->id])->json();

    expect($same['targetFree'])->toBeTrue()
        ->and($same['reasonRequired'])->toBeFalse();

    $deluxe = $this->actingAs($setup['user'])->postJson($url, [...$dates, 'room_id' => $setup['rooms']['201']->id])->json();

    expect($deluxe['roomTypeAllowed'])->toBeTrue()
        ->and($deluxe['targetFree'])->toBeTrue();
});

test('the move-stay preview refuses a room type the plan does not sell and a foreign room', function () {
    $setup = moveStaySetup(linkDeluxe: false);
    $url = route('reservations.units.move-stay-preview', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);
    $dates = ['arrival_date' => today()->addDay()->toDateString(), 'departure_date' => today()->addDays(4)->toDateString()];

    $deluxe = $this->actingAs($setup['user'])->postJson($url, [...$dates, 'room_id' => $setup['rooms']['201']->id])->assertOk()->json();

    expect($deluxe['roomTypeAllowed'])->toBeFalse()
        ->and($deluxe['targetFree'])->toBeFalse();

    $this->actingAs($setup['user'])->postJson($url, [...$dates, 'room_id' => Room::factory()->create()->id])->assertOk()->assertJsonPath('error', fn (?string $error): bool => $error !== null);

    $this->actingAs(User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]))
        ->postJson($url, [...$dates, 'room_id' => $setup['rooms']['102']->id])->assertForbidden();
});
