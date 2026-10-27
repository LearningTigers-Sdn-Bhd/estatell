<?php

require_once __DIR__.'/../Support/DayUseSetup.php';

use App\Enums\BookingSourceType;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Database\Factories\BookingSourceFactory;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 14)->setTime(15, 0));
});

/**
 * A live property with the business date today (2026-10-14, 15:00), the Day use plan (2 to 6 hours, 08:00 to 18:00)
 * priced today for 2 to 6 hours and for 10 hours (160.00), and the Walk-in source.
 *
 * @return array<string, mixed>
 */
function walkInDayUseSetup(): array
{
    $setup = dayUseHttpSetup();
    seedDayUseCodes($setup['property']);
    $setup['property']->forceFill(['status' => 'live'])->save();
    $setup['property']->settings->update(['card_required_for_check_in' => false, 'time_zone' => 'UTC']);
    BusinessDate::factory()->create(['property_id' => $setup['property']->id, 'date' => '2026-10-14']);

    $rate = Rate::factory()->create(['rate_plan_id' => $setup['plan']->id, 'room_type_id' => $setup['roomType']->id, 'date' => '2026-10-14', 'amount' => null]);

    foreach ([2 => '60.00', 3 => '80.00', 10 => '160.00'] as $hours => $amount) {
        $rate->hourPrices()->create(['hours' => $hours, 'amount' => $amount]);
    }

    Rate::factory()->create(['rate_plan_id' => $setup['basic']->id, 'room_type_id' => $setup['roomType']->id, 'date' => '2026-10-14', 'amount' => '100.00']);

    return $setup;
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $line
 * @return array<string, mixed>
 */
function walkInDayUseBody(array $setup, array $line = [], string $startTime = '02:00'): array
{
    return [
        'booking_source_id' => BookingSourceFactory::systemFor($setup['property']->id, BookingSourceType::WalkIn)->id,
        'stay_type' => 'day_use',
        'arrival_date' => '2026-10-14',
        'start_time' => $startTime,
        'guests' => [['guest_id' => $setup['guest']->id, 'stays_in' => 0]],
        'billing_mode' => 'master_pays',
        'lines' => [[
            'room_type_id' => $setup['roomType']->id,
            'rate_plan_id' => $setup['basic']->id,
            'adults' => 2,
            'hours' => 10,
            'room_id' => $setup['rooms'][0]->id,
            'override_total' => '150.00',
            ...$line,
        ]],
    ];
}

function walkInDayUseUrl(array $setup): string
{
    return route('reservations.store-walk-in', ['property' => $setup['property']->code]);
}

test('a walk-in day use that started earlier today is checked in at its start with the entered price posted', function () {
    $setup = walkInDayUseSetup();

    $this->actingAs($setup['user'])->post(walkInDayUseUrl($setup), walkInDayUseBody($setup))->assertSessionHasNoErrors();

    $unit = ReservationUnit::query()->sole();

    expect($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->checked_in_at->format('H:i'))->toBe('02:00')
        ->and($unit->dayUse->starts_at)->toStartWith('02:00')
        ->and(FolioLine::query()->where('idempotency_key', 'dayuse:'.$unit->id)->exists())->toBeTrue();
});

test('a walk-in day use skips the window and the hours of the plan when it started earlier today', function () {
    $setup = walkInDayUseSetup();
    $line = ['rate_plan_id' => $setup['plan']->id, 'override_total' => null];

    $this->actingAs($setup['user'])->post(walkInDayUseUrl($setup), walkInDayUseBody($setup, $line))->assertSessionHasNoErrors();

    $unit = ReservationUnit::query()->sole();

    expect($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->dayUse->amount)->toBe('160.00')
        ->and($unit->dayUse->hours)->toBe(10);
});

test('a walk-in day use started earlier today still needs a price for its hours on the plan', function () {
    $setup = walkInDayUseSetup();
    $line = ['rate_plan_id' => $setup['plan']->id, 'override_total' => null, 'hours' => 9];

    $this->actingAs($setup['user'])->post(walkInDayUseUrl($setup), walkInDayUseBody($setup, $line))->assertSessionHasErrors('lines.0.hours');

    expect(Reservation::query()->count())->toBe(0);
});

test('the plan window still applies to a walk-in day use that starts later today', function () {
    $setup = walkInDayUseSetup();
    $line = ['rate_plan_id' => $setup['plan']->id, 'override_total' => null, 'hours' => 3];

    $this->actingAs($setup['user'])->post(walkInDayUseUrl($setup), walkInDayUseBody($setup, $line, '17:00'))->assertSessionHasErrors('lines.0.start_time');

    expect(Reservation::query()->count())->toBe(0);
});

test('a walk-in day use that starts later today is checked in now, not at its start', function () {
    $setup = walkInDayUseSetup();

    $this->actingAs($setup['user'])->post(walkInDayUseUrl($setup), walkInDayUseBody($setup, ['hours' => 3], '16:00'))->assertSessionHasNoErrors();

    expect(ReservationUnit::query()->sole()->checked_in_at->format('H:i'))->toBe('15:00');
});

test('a walk-in day use refuses while the night audit is pending and the preview says so', function () {
    $setup = walkInDayUseSetup();
    BusinessDate::query()->where('property_id', $setup['property']->id)->update(['date' => '2026-10-13']);

    $this->actingAs($setup['user'])->post(walkInDayUseUrl($setup), walkInDayUseBody($setup))->assertSessionHasErrors(['form' => 'Run the night audit first.']);
    $this->postJson(route('reservations.walk-in-payment', ['property' => $setup['property']->code]), walkInDayUseBody($setup))
        ->assertOk()->assertJsonPath('errors.form', 'Run the night audit first.');

    expect(Reservation::query()->count())->toBe(0);
});

test('day-use-offers lists every priced hour for a start that has passed today', function () {
    $setup = walkInDayUseSetup();
    $query = ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'date' => '2026-10-14'];

    $this->actingAs($setup['user'])->getJson(route('reservations.day-use-offers', ['property' => $setup['property']->code, ...$query, 'start_time' => '02:00']))
        ->assertOk()
        ->assertJsonPath('offers.0.hours', 2)
        ->assertJsonPath('offers.2.hours', 10)
        ->assertJsonPath('offers.2.endsAt', '12:00');

    $this->getJson(route('reservations.day-use-offers', ['property' => $setup['property']->code, ...$query, 'start_time' => '17:00']))
        ->assertOk()->assertJsonPath('offers', []);
});
