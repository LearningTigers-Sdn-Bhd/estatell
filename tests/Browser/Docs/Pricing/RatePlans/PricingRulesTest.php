<?php

use App\Actions\Rates\CreateDerivedRatePlan;
use App\Actions\Rates\CreateRatePlan;
use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Models\RatePlan;

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('per room pricing', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Half Board')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.pricing.edit', [$this->property, $plan]), height: 1100)
        ->waitForText('Save prices');

    $this->shoot($page, 'per-room-pricing', badges: ['Set prices directly', 'Follow another plan', 'Dates', 'Save prices']);
});

test('per guest pricing', function () {
    $roomTypeIds = $this->property->roomTypes()->whereIn('name', ['Garden Room', 'Sea View Room'])->pluck('id')->all();
    $plan = app(CreateRatePlan::class)($this->property, auth()->user(), [
        'name' => 'Per Person Rate',
        'pricing_basis' => PricingBasis::PerGuest->value,
    ], $roomTypeIds);

    $page = $this->open(route('settings.rate-plans.pricing.edit', [$this->property, $plan]), height: 1100)
        ->waitForText('Save prices');

    $this->shoot($page, 'per-guest-pricing');
});

test('per bed pricing', function () {
    $this->signInAsScenariosOwner();
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Dorm Bed')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.pricing.edit', [$this->property, $plan]))
        ->waitForText('Save prices');

    $this->shoot($page, 'per-bed-pricing');
});

test('package pricing', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Dive Package')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.pricing.edit', [$this->property, $plan]), height: 1300)
        ->waitForText('Arrival days');

    $this->shoot($page, 'package-pricing', badges: ['Arrival days', 'Arrival dates', 'Price']);
});

test('day use pricing', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Day use')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.pricing.edit', [$this->property, $plan]), height: 1100)
        ->waitForText('Save prices');

    $this->shoot($page, 'day-use-pricing');
});

test('follow another plan', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Bed & Breakfast')->firstOrFail();
    $derived = app(CreateDerivedRatePlan::class)(
        auth()->user(), $plan, 'Member Rate', RateDerivation::Percent, '-10',
        $plan->roomTypes()->pluck('room_types.id')->all(), [],
    );

    $page = $this->open(route('settings.rate-plans.pricing.edit', [$this->property, $derived]), height: 1000)
        ->waitForText('Direction')
        ->wait(1.5);

    $this->shoot($page, 'following', badges: ['Follows', 'Direction', 'Value', 'Unit']);

    $page->click('Set prices directly')->waitForText('Stop following');

    $this->shoot($page, 'stop-following', badges: ['Stop following']);
});

test('follow form', function () {
    $roomTypeIds = $this->property->roomTypes()->whereIn('name', ['Garden Room', 'Sea View Room'])->pluck('id')->all();
    $plan = app(CreateRatePlan::class)($this->property, auth()->user(), [
        'name' => 'Member Rate',
        'pricing_basis' => PricingBasis::PerRoom->value,
    ], $roomTypeIds);

    $page = $this->open(route('settings.rate-plans.pricing.edit', [$this->property, $plan]), height: 1000)
        ->waitForText('Follow another plan')
        ->click('Follow another plan')
        ->waitForText('Direction')
        ->type('#derivation-value', '10')
        ->wait(1.5);

    $this->shoot($page, 'follow-form', badges: ['Follows', 'Direction', 'Value', 'Unit']);
});
