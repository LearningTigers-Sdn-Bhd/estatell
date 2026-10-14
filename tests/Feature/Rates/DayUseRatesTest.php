<?php

use App\Actions\Rates\ExtendRates;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RateHourPrice;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rates\RatesEndingCheck;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{0: User, 1: Property, 2: RatePlan, 3: RoomType} */
function dayUseRatesSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->dayUse(2, 4)->create(['name' => 'Day use']);
    $roomType = RoomType::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    return [$owner, $property, $plan, $roomType];
}

/** @param  array<int, string|null>  $prices  Amount by hour count, null leaves the hour blank. */
function dayUseInput(RoomType $roomType, array $prices, array $overrides = []): array
{
    $hours = collect($prices)->filter(fn ($amount) => $amount !== null)
        ->map(fn ($amount, $hour) => ['hours' => $hour, 'amount' => $amount])->values()->all();
    $days = collect(range(1, 7))->map(fn (int $weekday): array => ['weekday' => $weekday, 'hours' => $hours])->all();

    return array_merge([
        'from' => '2026-10-05',
        'to' => '2026-10-11',
        'room_types' => [['room_type_id' => $roomType->id, 'days' => $days]],
    ], $overrides);
}

test('the pricing form saves hour prices for every date and keeps rates.amount empty', function () {
    [$owner, $property, $plan, $roomType] = dayUseRatesSetup();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), dayUseInput($roomType, [2 => '60', 3 => '80.50', 4 => '100.00']))
        ->assertRedirect(route('settings.rate-plans.pricing.edit', [$property->code, $plan]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Prices saved for 7 dates.']);

    $rate = Rate::query()->where('rate_plan_id', $plan->id)->whereDate('date', '2026-10-07')->sole();

    expect(Rate::query()->where('rate_plan_id', $plan->id)->count())->toBe(7)
        ->and($rate->amount)->toBeNull()
        ->and($rate->hourPrices()->orderBy('hours')->pluck('amount', 'hours')->all())->toBe([2 => '60.00', 3 => '80.50', 4 => '100.00']);
});

test('saving a date replaces its hour prices inside the hours range and keeps those outside', function () {
    [$owner, $property, $plan, $roomType] = dayUseRatesSetup();
    $this->actingAs($owner)->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), dayUseInput($roomType, [2 => '60', 3 => '80', 4 => '100']));
    $rate = Rate::query()->where('rate_plan_id', $plan->id)->whereDate('date', '2026-10-07')->sole();
    $rate->hourPrices()->create(['hours' => 8, 'amount' => '200.00']);

    $this->actingAs($owner)->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), dayUseInput($roomType, [2 => '70', 3 => null, 4 => '110']))->assertRedirect();

    expect($rate->hourPrices()->orderBy('hours')->pluck('amount', 'hours')->all())->toBe([2 => '70.00', 4 => '110.00', 8 => '200.00'])
        ->and(Rate::query()->where('rate_plan_id', $plan->id)->count())->toBe(7);
});

test('a day use plan refuses an hour outside its range, a repeated hour, an amount and nightly restrictions', function (array $change) {
    [$owner, $property, $plan, $roomType] = dayUseRatesSetup();
    $input = dayUseInput($roomType, [2 => '60']);
    $input = array_replace_recursive($input, $change);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), $input)
        ->assertSessionHasErrors();

    expect(Rate::query()->count())->toBe(0);
})->with([
    'hour above max' => [['room_types' => [['days' => [0 => ['hours' => [['hours' => 5, 'amount' => '10']]]]]]]],
    'hour below min' => [['room_types' => [['days' => [0 => ['hours' => [['hours' => 1, 'amount' => '10']]]]]]]],
    'an amount' => [['room_types' => [['days' => [0 => ['amount' => '10']]]]]],
    'min stay' => [['restrictions' => ['min_stay' => 2]]],
    'closed to arrival' => [['restrictions' => ['closed_to_arrival' => true]]],
]);

test('other plans refuse hour prices and a repeated hour is rejected', function () {
    [$owner, $property, $plan, $roomType] = dayUseRatesSetup();
    $basic = RatePlan::factory()->for($property)->create();
    $basic->roomTypes()->attach($roomType);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.pricing.update', [$property->code, $basic]), dayUseInput($roomType, [2 => '60']))
        ->assertSessionHasErrors('room_types.0.days.0.hours');

    $repeat = dayUseInput($roomType, [2 => '60']);
    $repeat['room_types'][0]['days'][0]['hours'][] = ['hours' => 2, 'amount' => '70'];

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), $repeat)
        ->assertSessionHasErrors();
});

test('stop sell saves on a day use plan', function () {
    [$owner, $property, $plan, $roomType] = dayUseRatesSetup();
    $input = dayUseInput($roomType, [2 => '60'], ['restrictions' => ['stop_sell' => true]]);

    $this->actingAs($owner)->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), $input)->assertRedirect();

    expect(Rate::query()->where('rate_plan_id', $plan->id)->where('stop_sell', true)->count())->toBe(7);
});

test('extending copies the hour prices forward', function () {
    [$owner, $property, $plan, $roomType] = dayUseRatesSetup();
    $this->actingAs($owner)->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), dayUseInput($roomType, [2 => '60', 3 => '80']));

    $written = app(ExtendRates::class)($owner, $plan, Date::parse('2026-10-05'));

    $later = Rate::query()->where('rate_plan_id', $plan->id)->whereDate('date', '2026-11-03')->sole();

    expect($written)->toBeGreaterThan(300)
        ->and($later->hourPrices()->orderBy('hours')->pluck('amount', 'hours')->all())->toBe([2 => '60.00', 3 => '80.00']);
});

test('a day use plan counts as priced by its hour prices', function () {
    [$owner, $property, $plan, $roomType] = dayUseRatesSetup();
    $check = app(RatesEndingCheck::class);

    expect($check->pricedUntil($plan)[$roomType->id])->toBeNull();

    $rate = Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => '2026-12-01', 'amount' => null]);
    RateHourPrice::factory()->create(['rate_id' => $rate->id, 'hours' => 2]);

    expect(substr($check->pricedUntil($plan)[$roomType->id], 0, 10))->toBe('2026-12-01')
        ->and($check->pricedUntilByPlan([$plan->id])[$plan->id][$roomType->id])->toStartWith('2026-12-01');
});

test('the rate calendar shows the lowest hour price of a date and lists the hours', function () {
    [$owner, $property, $plan, $roomType] = dayUseRatesSetup();
    $rate = Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => '2026-10-12', 'amount' => null]);
    RateHourPrice::factory()->create(['rate_id' => $rate->id, 'hours' => 4, 'amount' => '100.00']);
    RateHourPrice::factory()->create(['rate_id' => $rate->id, 'hours' => 2, 'amount' => '40.00']);

    $this->actingAs($owner)
        ->get(route('rates.calendar', [$property->code, 'from' => '2026-10-12', 'to' => '2026-10-14']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('rates/calendar')
            ->where('calendar.roomTypes.0.plans.0.pricingBasis', 'day_use')
            ->where('calendar.roomTypes.0.plans.0.cells.0.amount', '40.00')
            ->where('calendar.roomTypes.0.plans.0.cells.0.hours', [['hours' => 2, 'amount' => '40.00'], ['hours' => 4, 'amount' => '100.00']]));
});

test('the pricing page is limited to the property of its owner', function () {
    [, $property, $plan] = dayUseRatesSetup();
    $other = User::factory()->owner()->create();

    $this->actingAs($other)->get(route('settings.rate-plans.pricing.edit', [$property->code, $plan]))->assertNotFound();
});
