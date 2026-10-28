<?php

require_once __DIR__.'/../Support/DayUseSetup.php';

use App\Enums\BookingSourceType;
use App\Enums\DefaultRoleEnum;
use App\Enums\HousekeepingStatus;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomStatus;
use App\Models\User;
use Database\Factories\BookingSourceFactory;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 14)->setTime(15, 0));
});

/**
 * A live property that went live on 2026-10-10 and trades on 2026-10-14, with the Day use plan, the Basic plan and the
 * Owner as the user.
 *
 * @return array<string, mixed>
 */
function pastDayUseSetup(): array
{
    $setup = dayUseHttpSetup();
    seedDayUseCodes($setup['property']);
    $setup['property']->forceFill(['status' => 'live'])->save();
    $setup['property']->settings->update(['card_required_for_check_in' => false, 'time_zone' => 'UTC']);
    BusinessDate::factory()->closed()->create(['property_id' => $setup['property']->id, 'date' => '2026-10-10']);
    BusinessDate::factory()->create(['property_id' => $setup['property']->id, 'date' => '2026-10-14']);

    return $setup;
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pastDayUseBody(array $setup, array $overrides = []): array
{
    return [
        'booking_source_id' => BookingSourceFactory::systemFor($setup['property']->id, BookingSourceType::WalkIn)->id,
        'mode' => 'past',
        'stay_type' => 'day_use',
        'status' => 'checked_out',
        'arrival_date' => '2026-10-12',
        'start_time' => '02:00',
        'end_time' => '12:00',
        'reason' => 'Walk-in entered late',
        'guests' => [['guest_id' => $setup['guest']->id, 'stays_in' => 0]],
        'billing_mode' => 'master_pays',
        'lines' => [[
            'room_type_id' => $setup['roomType']->id,
            'rate_plan_id' => $setup['basic']->id,
            'adults' => 2,
            'hours' => 10,
            'room_id' => $setup['rooms'][0]->id,
            'override_total' => '150.00',
        ]],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $setup
 */
function pastDayUseUrl(array $setup): string
{
    return route('reservations.store-past', ['property' => $setup['property']->code]);
}

test('a past day use is checked in at its start and out at its end, with the block posted on the business date for its own day', function () {
    $setup = pastDayUseSetup();

    $this->actingAs($setup['user'])->post(pastDayUseUrl($setup), pastDayUseBody($setup))->assertSessionHasNoErrors();

    $unit = ReservationUnit::query()->sole();
    $block = FolioLine::query()->where('idempotency_key', 'dayuse:'.$unit->id)->sole();

    expect($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->checked_in_at->format('Y-m-d H:i'))->toBe('2026-10-12 02:00')
        ->and($unit->checked_out_at->format('Y-m-d H:i'))->toBe('2026-10-12 12:00')
        ->and($unit->dayUse->date->toDateString())->toBe('2026-10-12')
        ->and($unit->dayUse->hours)->toBe(10)
        ->and($block->posting_date->toDateString())->toBe('2026-10-14')
        ->and($block->service_date->toDateString())->toBe('2026-10-12')
        ->and(RoomStatus::query()->where('room_id', $setup['rooms'][0]->id)->where('status', HousekeepingStatus::Dirty)->exists())->toBeFalse();
});

test('a past day use skips the window and hours of the plan and quotes the hour price', function () {
    $setup = pastDayUseSetup();
    $rate = Rate::factory()->create(['rate_plan_id' => $setup['plan']->id, 'room_type_id' => $setup['roomType']->id, 'date' => '2026-10-12', 'amount' => null]);
    $rate->hourPrices()->create(['hours' => 10, 'amount' => '160.00']);
    $line = ['rate_plan_id' => $setup['plan']->id, 'override_total' => null];

    $this->actingAs($setup['user'])->post(pastDayUseUrl($setup), pastDayUseBody($setup, ['lines' => [[...pastDayUseBody($setup)['lines'][0], ...$line]]]))->assertSessionHasNoErrors();

    expect(ReservationUnit::query()->sole()->dayUse->amount)->toBe('160.00');
});

test('a past day use is refused for a bad date, status, end or room and nothing is saved', function (array $overrides, string $field) {
    $setup = pastDayUseSetup();

    $this->actingAs($setup['user'])->post(pastDayUseUrl($setup), pastDayUseBody($setup, $overrides))->assertSessionHasErrors($field);

    expect(Reservation::query()->count())->toBe(0)
        ->and(FolioLine::query()->count())->toBe(0);
})->with([
    'today' => [['arrival_date' => '2026-10-14'], 'arrival_date'],
    'before go-live' => [['arrival_date' => '2026-10-09'], 'arrival_date'],
    'still in house' => [['status' => 'in_house'], 'status'],
    'no end' => [['end_time' => null], 'end_time'],
    'end before start' => [['end_time' => '01:00'], 'end_time'],
    'no reason' => [['reason' => ''], 'reason'],
]);

test('a past day use is refused when its room is taken in the block', function () {
    $setup = pastDayUseSetup();

    $this->actingAs($setup['user'])->post(pastDayUseUrl($setup), pastDayUseBody($setup))->assertSessionHasNoErrors();
    $this->actingAs($setup['user'])->post(pastDayUseUrl($setup), pastDayUseBody($setup, ['start_time' => '10:00', 'end_time' => '14:00']))->assertSessionHasErrors();

    expect(Reservation::query()->count())->toBe(1);
});

test('only the Past stay mode takes a day use, not a migrated stay', function () {
    $setup = pastDayUseSetup();

    $this->actingAs($setup['user'])->post(pastDayUseUrl($setup), pastDayUseBody($setup, ['mode' => 'migrated', 'old_folio' => 'one_amount', 'arrival_date' => '2026-10-09']))->assertSessionHasErrors();

    expect(Reservation::query()->count())->toBe(0);
});

test('a user without folio.manual cannot save a past day use', function () {
    $setup = pastDayUseSetup();
    $user = User::factory()->for($setup['property'])->withRole(DefaultRoleEnum::FrontDesk)->create();

    $this->actingAs($user)->post(pastDayUseUrl($setup), pastDayUseBody($setup))->assertForbidden();

    expect(Reservation::query()->count())->toBe(0);
});
