<?php

use App\Actions\FrontDesk\CheckInEndedStay;
use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\NightAudit\PostMissedNights;
use App\Enums\EndedStayChoice;
use App\Enums\StayType;
use App\Enums\UnitAction;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Reservations\UnitActionMenu;
use App\Services\Reservations\UnitActionPreview;

require_once __DIR__.'/../Support/PastStaySetup.php';

/**
 * The past stay setup in Kuala Lumpur time and a Confirmed unit for the night of 13 Oct, due to leave on the business date.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: array<string, mixed>, 1: ReservationUnit}
 */
function endedStaySetup(string $arrival = '2026-10-13', string $departure = '2026-10-14', array $attributes = []): array
{
    $setup = pastStaySetup();
    $setup['property']->settings()->update(['time_zone' => 'Asia/Kuala_Lumpur', 'card_required_for_check_in' => false]);
    setPermissionsTeamId($setup['property']->id);

    return [$setup, pastStayUnit($setup, $arrival, $departure, $attributes)];
}

/**
 * @return list<string>
 */
function endedStayNightLines(): array
{
    return FolioLine::query()->where('idempotency_key', 'like', 'night:%')->whereNull('parent_line_id')->get()
        ->map(fn ($line): string => $line->service_date->toDateString().'/'.$line->posting_date->toDateString())->all();
}

function endedStayUser(array $setup, array $permissions = ['front-desk.check-in', 'reservations.view', 'reservations.manage']): User
{
    return User::factory()->for($setup['property'])->withPermissions($permissions)->create();
}

function endedStayUrl(array $setup, ReservationUnit $unit): string
{
    return route('reservations.units.check-in', ['property' => $setup['property'], 'reservation' => $unit->reservation_id, 'unit' => $unit->id]);
}

test('a guest who arrived after midnight checks in for the last night, with the real time, and the night is charged once', function () {
    [$setup, $unit] = endedStaySetup();

    $this->actingAs(endedStayUser($setup))
        ->post(endedStayUrl($setup, $unit), ['ended_stay_choice' => 'arrived_last_night', 'arrived_at' => '02:00'])
        ->assertSessionHasNoErrors();

    $unit->refresh();

    expect($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->checked_in_at->toDateTimeString())->toBe('2026-10-13 18:00:00')
        ->and($unit->departure_date->toDateString())->toBe('2026-10-14')
        ->and(endedStayNightLines())->toBe(['2026-10-13/2026-10-14']);

    app(PostMissedNights::class)($setup['user'], $unit);

    expect(endedStayNightLines())->toBe(['2026-10-13/2026-10-14']);
});

test('an afternoon or evening time is on the last night', function () {
    [$setup, $unit] = endedStaySetup();

    $result = app(CheckInEndedStay::class)($setup['user'], $unit, EndedStayChoice::ArrivedLastNight, '21:30');

    expect($result->stay->isRefused())->toBeFalse()
        ->and($unit->refresh()->checked_in_at->toDateTimeString())->toBe('2026-10-13 13:30:00');
});

test('an arrival time later than now is refused and saves nothing', function () {
    $this->travelTo('2026-10-14 01:00:00');
    [$setup, $unit] = endedStaySetup();

    $result = app(CheckInEndedStay::class)($setup['user'], $unit, EndedStayChoice::ArrivedLastNight, '11:00');

    expect($result->stay->refusal)->toBe(UnitStayResult::REFUSAL_ARRIVED_AT_IN_FUTURE)
        ->and($unit->refresh()->status)->toBe(UnitStatus::Confirmed)
        ->and(endedStayNightLines())->toBe([]);
});

test('the arrival time is required for a guest who arrived last night', function () {
    [$setup, $unit] = endedStaySetup();

    $this->actingAs(endedStayUser($setup))
        ->post(endedStayUrl($setup, $unit), ['ended_stay_choice' => 'arrived_last_night'])
        ->assertSessionHasErrors('arrived_at');

    expect($unit->refresh()->status)->toBe(UnitStatus::Confirmed);
});

test('a guest arriving today moves to today and tomorrow and checks in, and the old night is not charged', function () {
    [$setup, $unit] = endedStaySetup();

    $this->actingAs(endedStayUser($setup))
        ->post(endedStayUrl($setup, $unit), ['ended_stay_choice' => 'arriving_today'])
        ->assertSessionHasNoErrors();

    $unit->refresh();

    expect($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->arrival_date->toDateString())->toBe('2026-10-14')
        ->and($unit->departure_date->toDateString())->toBe('2026-10-15')
        ->and($unit->nights()->pluck('date')->map->toDateString()->all())->toBe(['2026-10-14'])
        ->and($unit->nights()->value('room_id'))->toBe($setup['rooms'][0]->id)
        ->and(endedStayNightLines())->toBe([]);
});

test('a refused check-in after the move saves neither the dates nor the check-in', function () {
    [$setup, $unit] = endedStaySetup();
    $setup['property']->settings()->update(['auto_assign_rooms' => false]);
    $unit->nights()->update(['room_id' => null]);

    $result = app(CheckInEndedStay::class)($setup['user'], $unit, EndedStayChoice::ArrivingToday);

    expect($result->stay->refusal)->toBe(UnitStayResult::REFUSAL_NO_ROOM_ASSIGNED);

    $unit->refresh();

    expect($unit->status)->toBe(UnitStatus::Confirmed)
        ->and($unit->arrival_date->toDateString())->toBe('2026-10-13')
        ->and($unit->departure_date->toDateString())->toBe('2026-10-14');
});

test('moving to today needs the right to change dates, and the card says so', function () {
    [$setup, $unit] = endedStaySetup();
    $user = endedStayUser($setup, ['front-desk.check-in', 'reservations.view']);

    $this->actingAs($user)
        ->post(endedStayUrl($setup, $unit), ['ended_stay_choice' => 'arriving_today'])
        ->assertForbidden();

    expect($unit->refresh()->arrival_date->toDateString())->toBe('2026-10-13')
        ->and(app(UnitActionPreview::class)->checkIn($unit, $user)->endedStay->arrivingTodayBlocked)->toBe('Ask someone who can change dates.');
});

test('the preview and the menu offer the cards on the departure date, with the new total', function () {
    [$setup, $unit] = endedStaySetup();
    $user = endedStayUser($setup);
    $preview = app(UnitActionPreview::class)->checkIn($unit, $user);

    expect($preview->endedStay->arrival)->toBe('13 Oct')
        ->and($preview->endedStay->departure)->toBe('14 Oct')
        ->and($preview->endedStay->lastNight)->toBe('13 Oct')
        ->and($preview->endedStay->today)->toBe('14 Oct')
        ->and($preview->endedStay->tomorrow)->toBe('15 Oct')
        ->and($preview->endedStay->newTotal)->toBe('100.00')
        ->and($preview->endedStay->arrivingTodayBlocked)->toBeNull()
        ->and($preview->room)->toBe($setup['rooms'][0]->number)
        ->and(collect(app(UnitActionMenu::class)->for($user, $unit))->pluck('key')->all())->toContain(UnitAction::CheckIn);
});

test('the cards are not offered and the choice is refused', function (string $arrival, string $departure, array $attributes) {
    [$setup, $unit] = endedStaySetup($arrival, $departure, $attributes);

    expect(app(UnitActionPreview::class)->checkIn($unit, endedStayUser($setup))->endedStay)->toBeNull()
        ->and(app(CheckInEndedStay::class)($setup['user'], $unit, EndedStayChoice::ArrivedLastNight, '02:00')->stay->refusal)->toBe(UnitStayResult::REFUSAL_STAY_ENDED);
})->with([
    'ended two days ago' => ['2026-10-12', '2026-10-13', []],
    'still has a night' => ['2026-10-14', '2026-10-15', []],
    'already checked in' => ['2026-10-13', '2026-10-14', ['status' => UnitStatus::CheckedIn, 'checked_in_at' => '2026-10-13 15:00:00']],
    'day use' => ['2026-10-14', '2026-10-14', ['stay_type' => StayType::DayUse]],
]);

test('a normal check-in on the departure date is still refused without a choice', function () {
    [$setup, $unit] = endedStaySetup();

    $this->actingAs(endedStayUser($setup))
        ->post(endedStayUrl($setup, $unit), [])
        ->assertSessionHasErrors();

    expect($unit->refresh()->status)->toBe(UnitStatus::Confirmed);
});

/**
 * A group of two late rooms, plus any more units.
 *
 * @return array{0: array<string, mixed>, 1: ReservationUnit, 2: ReservationUnit}
 */
function endedStayGroup(): array
{
    [$setup, $first] = endedStaySetup();
    $second = ReservationUnit::factory()->for($first->reservation)->withNights()->create([
        'room_type_id' => $setup['roomType']->id,
        'rate_plan_id' => $setup['plan']->id,
        'arrival_date' => '2026-10-13',
        'departure_date' => '2026-10-14',
    ]);
    $second->nights()->update(['room_id' => $setup['rooms'][1]->id]);

    return [$setup, $first, $second];
}

function endedStayAllUrl(array $setup, ReservationUnit $unit): string
{
    return route('reservations.check-in-all', ['property' => $setup['property'], 'reservation' => $unit->reservation_id]);
}

test('check in all checks in late rooms for last night', function () {
    [$setup, $first, $second] = endedStayGroup();

    $this->actingAs(endedStayUser($setup))
        ->post(endedStayAllUrl($setup, $first), ['unit_ids' => [$first->id, $second->id], 'ended_stay_choice' => 'arrived_last_night', 'arrived_at' => '02:00'])
        ->assertSessionHasNoErrors();

    expect($first->refresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($second->refresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($second->checked_in_at->toDateTimeString())->toBe('2026-10-13 18:00:00')
        ->and(endedStayNightLines())->toBe(['2026-10-13/2026-10-14', '2026-10-13/2026-10-14']);
});

test('check in all moves late rooms to today, and a refused room stays behind with its own error', function () {
    [$setup, $first, $second] = endedStayGroup();
    $setup['property']->settings()->update(['auto_assign_rooms' => false]);
    $second->nights()->update(['room_id' => null]);

    $this->actingAs(endedStayUser($setup))
        ->post(endedStayAllUrl($setup, $first), ['unit_ids' => [$first->id, $second->id], 'ended_stay_choice' => 'arriving_today'])
        ->assertSessionHasErrors('units.'.$second->id);

    expect($first->refresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($first->departure_date->toDateString())->toBe('2026-10-15')
        ->and($second->refresh()->status)->toBe(UnitStatus::Confirmed)
        ->and($second->departure_date->toDateString())->toBe('2026-10-14');
});

test('check in all applies the answer only to late rooms', function () {
    [$setup, $first] = endedStaySetup();
    $normal = ReservationUnit::factory()->for($first->reservation)->withNights()->create([
        'room_type_id' => $setup['roomType']->id,
        'rate_plan_id' => $setup['plan']->id,
        'arrival_date' => '2026-10-14',
        'departure_date' => '2026-10-16',
    ]);
    $normal->nights()->update(['room_id' => $setup['rooms'][1]->id]);

    $this->actingAs(endedStayUser($setup))
        ->post(endedStayAllUrl($setup, $first), ['unit_ids' => [$first->id, $normal->id], 'ended_stay_choice' => 'arriving_today'])
        ->assertSessionHasNoErrors();

    expect($first->refresh()->arrival_date->toDateString())->toBe('2026-10-14')
        ->and($normal->refresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($normal->departure_date->toDateString())->toBe('2026-10-16');
});

test('check in all needs the right to change dates to move rooms to today', function () {
    [$setup, $first, $second] = endedStayGroup();

    $this->actingAs(endedStayUser($setup, ['front-desk.check-in', 'reservations.view']))
        ->post(endedStayAllUrl($setup, $first), ['unit_ids' => [$first->id, $second->id], 'ended_stay_choice' => 'arriving_today'])
        ->assertForbidden();

    expect($first->refresh()->status)->toBe(UnitStatus::Confirmed);
});

test('the check in all preview marks late rooms ready and asks the question', function () {
    [$setup, $first, $second] = endedStayGroup();
    $preview = app(UnitActionPreview::class)->checkInAll($first->reservation, endedStayUser($setup));

    expect($preview->rows[0]['ready'])->toBeTrue()
        ->and($preview->rows[0]['endedStay'])->toBeTrue()
        ->and($preview->rows[0]['note'])->toBeNull()
        ->and($preview->rows[0]['moveTotal'])->toBe('100.00')
        ->and($preview->rows[0]['room'])->toBe($setup['rooms'][0]->number)
        ->and($preview->endedStay->stayDates)->toBe('13 Oct → 14 Oct')
        ->and($preview->endedStay->oneNight)->toBeTrue()
        ->and($preview->endedStay->arrivingTodayBlocked)->toBeNull();
});
