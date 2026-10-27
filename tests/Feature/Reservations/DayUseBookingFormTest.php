<?php

require_once __DIR__.'/../Support/DayUseSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Enums\StayType;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\User;
use Database\Factories\BookingSourceFactory;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dayUseBody(array $setup, array $overrides = []): array
{
    return [
        'booking_source_id' => BookingSourceFactory::directFor($setup['property']->id)->id,
        'stay_type' => 'day_use',
        'arrival_date' => $setup['date']->toDateString(),
        'start_time' => '10:00',
        'guests' => [['guest_id' => $setup['guest']->id, 'stays_in' => 0]],
        'billing_mode' => 'master_pays',
        'lines' => [['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'hours' => 4, 'room_id' => $setup['rooms'][0]->id]],
        ...$overrides,
    ];
}

test('the booking pages send hasDayUse and the plan flags only when the property has an active day use plan', function () {
    $setup = dayUseHttpSetup();

    $this->actingAs($setup['user'])->get(route('reservations.new', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasDayUse', true)
            ->where('addUnitOptions.ratePlans.0.isDayUse', true)
            ->where('addUnitOptions.ratePlans.0.dayUseMinHours', 2)
            ->where('addUnitOptions.ratePlans.0.dayUseFrom', '08:00')
            ->where('addUnitOptions.ratePlans.0.dayUseUntil', '18:00')
            ->where('addUnitOptions.ratePlans.1.isDayUse', false));

    $setup['plan']->forceFill(['archived_at' => now()])->save();

    $this->actingAs($setup['user'])->get(route('reservations.walk-in', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('hasDayUse', false)->has('addUnitOptions.ratePlans', 1));
});

test('day-use-offers lists the priced hours inside the window with prices and end times', function () {
    $setup = dayUseHttpSetup();
    $query = ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'date' => $setup['date']->toDateString(), 'start_time' => '14:00'];

    $this->actingAs($setup['user'])->getJson(route('reservations.day-use-offers', ['property' => $setup['property']->code, ...$query]))
        ->assertOk()
        ->assertJsonPath('offers', [
            ['hours' => 2, 'amount' => '60.00', 'endsAt' => '16:00'],
            ['hours' => 3, 'amount' => '80.00', 'endsAt' => '17:00'],
            ['hours' => 4, 'amount' => '100.00', 'endsAt' => '18:00'],
        ]);

    $this->actingAs($setup['user'])->getJson(route('reservations.day-use-offers', ['property' => $setup['property']->code, ...$query, 'rate_plan_id' => $setup['basic']->id]))
        ->assertOk()->assertJsonPath('offers', []);
    $this->actingAs($setup['user'])->getJson(route('reservations.day-use-offers', ['property' => $setup['property']->code, ...$query, 'start_time' => 'nine']))
        ->assertUnprocessable();
});

test('quote answers the day use price, the stop sell warning and the free rooms', function () {
    $setup = dayUseHttpSetup();
    $body = ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'stay_type' => 'day_use', 'arrival_date' => $setup['date']->toDateString(), 'start_time' => '10:00', 'hours' => 4, 'adults' => 2];

    $this->actingAs($setup['user'])->postJson(route('reservations.quote', $setup['property']->code), $body)
        ->assertOk()
        ->assertJsonPath('nights', [])
        ->assertJsonPath('unitTotal', '100.00')
        ->assertJsonPath('lineTotal', '100.00')
        ->assertJsonPath('available', 2)
        ->assertJsonPath('violations', [])
        ->assertJsonPath('errors', []);

    Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', $setup['date'])->update(['stop_sell' => true]);

    $this->actingAs($setup['user'])->postJson(route('reservations.quote', $setup['property']->code), $body)
        ->assertOk()->assertJsonPath('unitTotal', '100.00')->assertJsonCount(1, 'violations');

    $this->actingAs($setup['user'])->postJson(route('reservations.quote', $setup['property']->code), [...$body, 'hours' => 9])
        ->assertOk()->assertJsonPath('unitTotal', null)->assertJsonPath('errors.hours', 'This plan does not sell that number of hours.');
});

test('free-units for a day use lists only the rooms free for those hours', function () {
    $setup = dayUseHttpSetup();
    createDayUseReservation($setup, [dayUseLine($setup)]);
    $query = ['room_type_id' => $setup['roomType']->id, 'stay_type' => 'day_use', 'arrival_date' => $setup['date']->toDateString(), 'start_time' => '12:00', 'hours' => 2];

    $this->actingAs($setup['user'])->getJson(route('reservations.free-units', ['property' => $setup['property']->code, ...$query]))
        ->assertOk()
        ->assertJsonPath('units.0.id', $setup['rooms'][1]->id)
        ->assertJsonCount(1, 'units');

    $this->actingAs($setup['user'])->getJson(route('reservations.free-units', ['property' => $setup['property']->code, ...$query, 'start_time' => '14:00']))
        ->assertJsonCount(2, 'units');
});

test('a day use quote uses an override but still refuses unsold hours', function () {
    $setup = dayUseHttpSetup();
    $body = ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'stay_type' => 'day_use', 'arrival_date' => $setup['date']->toDateString(), 'start_time' => '10:00', 'hours' => 4, 'adults' => 2, 'override_total' => '80.00'];

    $this->actingAs($setup['user'])->postJson(route('reservations.quote', $setup['property']->code), $body)
        ->assertJsonPath('unitTotal', '80.00')->assertJsonPath('lineTotal', '80.00')->assertJsonPath('isOverridden', true);
    $this->postJson(route('reservations.quote', $setup['property']->code), [...$body, 'hours' => 9])
        ->assertJsonPath('unitTotal', null)->assertJsonPath('errors.hours', 'This plan does not sell that number of hours.');
});

test('store creates a day use reservation with the guest attached and no room pick', function () {
    $setup = dayUseHttpSetup();

    $this->actingAs($setup['user'])->post(route('reservations.store', $setup['property']->code), dayUseBody($setup))
        ->assertSessionHasNoErrors();

    $reservation = Reservation::query()->sole();
    $unit = $reservation->units()->sole();

    expect($unit->stay_type)->toBe(StayType::DayUse)
        ->and($unit->dayUse->room_id)->toBe($setup['rooms'][0]->id)
        ->and($unit->dayUse->amount)->toBe('100.00')
        ->and($unit->departure_date->toDateString())->toBe($setup['date']->toDateString())
        ->and($unit->guests()->count())->toBe(1);
});

test('a refusal comes back keyed to the line', function (string $case) {
    $setup = dayUseHttpSetup();
    $line = fn (array $change) => [['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'hours' => 4, 'room_id' => $setup['rooms'][0]->id, ...$change]];
    [$body, $key] = match ($case) {
        'hours not sold' => [dayUseBody($setup, ['lines' => $line(['hours' => 9])]), 'lines.0.hours'],
        'no room' => [dayUseBody($setup, ['lines' => $line(['room_id' => null])]), 'lines.0.room_id'],
        'outside the window' => [dayUseBody($setup, ['start_time' => '16:00']), 'lines.0.start_time'],
    };

    $this->actingAs($setup['user'])->post(route('reservations.store', $setup['property']->code), $body)->assertSessionHasErrors($key);

    expect(Reservation::query()->count())->toBe(0);
})->with(['hours not sold', 'no room', 'outside the window']);

test('a day use booking needs a start time and no departure date', function () {
    $setup = dayUseHttpSetup();

    $this->actingAs($setup['user'])->post(route('reservations.store', $setup['property']->code), dayUseBody($setup, ['start_time' => null]))
        ->assertSessionHasErrors('start_time');
    $this->actingAs($setup['user'])->post(route('reservations.store', $setup['property']->code), dayUseBody($setup, ['stay_type' => null]))
        ->assertSessionHasErrors('departure_date');
});

test('the day use endpoints need reservations.manage and stay inside the property', function () {
    $setup = dayUseHttpSetup();
    $housekeeping = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();
    $other = dayUseHttpSetup();
    $query = ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'date' => $setup['date']->toDateString(), 'start_time' => '10:00'];

    $this->actingAs($housekeeping)->getJson(route('reservations.day-use-offers', ['property' => $housekeeping->property->code, ...$query]))->assertForbidden();
    $this->actingAs($setup['user'])->getJson(route('reservations.day-use-offers', ['property' => $other['property']->code, ...$query]))->assertNotFound();
    $this->actingAs($other['user'])->getJson(route('reservations.day-use-offers', ['property' => $other['property']->code, ...$query]))->assertOk()->assertJsonPath('offers', []);
});

test('the payload the booking form posts for a day use line is accepted', function () {
    $setup = dayUseHttpSetup();
    $body = dayUseBody($setup, [
        'external_reference' => '',
        'arrival_time' => '15:00',
        'departure_time' => '11:00',
        'departure_date' => $setup['date']->toDateString(),
        'group_name' => '',
        'company_id' => '',
        'credit_override_reason' => '',
        'notes' => '',
        'lines' => [[
            'room_type_id' => (string) $setup['roomType']->id,
            'rate_plan_id' => (string) $setup['plan']->id,
            'adults' => '2',
            'child_ages' => [],
            'beds' => '1',
            'discount_id' => '',
            'override_total' => '80.00',
            'override_reason' => '',
            'restriction_override_reason' => '',
            'add_ons' => [],
            'room_id' => (string) $setup['rooms'][0]->id,
            'bed_ids' => [],
            'hours' => '4',
        ]],
    ]);

    $this->actingAs($setup['user'])->post(route('reservations.store', $setup['property']->code), $body)->assertSessionHasNoErrors();

    expect(Reservation::query()->sole()->units()->sole()->dayUse->hours)->toBe(4)
        ->and(Reservation::query()->sole()->units()->sole()->dayUse->override_amount)->toBe('80.00');
});

test('the Add unit sheet posts a day use unit to an existing day use reservation', function () {
    $setup = dayUseHttpSetup();
    $reservation = createDayUseReservation($setup, [dayUseLine($setup)])->reservation;

    $this->actingAs($setup['user'])->post(route('reservations.units.store', ['property' => $setup['property']->code, 'reservation' => $reservation->id]), [
        'room_type_id' => (string) $setup['roomType']->id,
        'rate_plan_id' => (string) $setup['plan']->id,
        'arrival_date' => $setup['date']->toDateString(),
        'departure_date' => $setup['date']->toDateString(),
        'adults' => '2',
        'child_ages' => [],
        'override_total' => '50.00',
        'override_reason' => '',
        'restriction_override_reason' => '',
        'stay_type' => 'day_use',
        'start_time' => '14:00',
        'hours' => '2',
        'room_id' => (string) $setup['rooms'][0]->id,
    ])->assertSessionHasNoErrors();

    expect($reservation->units()->count())->toBe(2)
        ->and($reservation->units()->latest('id')->first()->dayUse->override_amount)->toBe('50.00');
});
