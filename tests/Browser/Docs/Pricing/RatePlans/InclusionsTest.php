<?php

use App\Models\RatePlan;

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('inclusions tab', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Half Board')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.inclusions.edit', [$this->property, $plan]))
        ->waitForText('Save inclusions');

    $this->shoot($page, 'inclusions-tab', badges: ['Charge item', 'Meal period', 'Charged', 'Quantity', 'Amount', 'Starts', 'Ends', 'Add inclusion']);
});

test('package inclusions', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Dive Package')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.inclusions.edit', [$this->property, $plan]), height: 1000)
        ->waitForText('Save inclusions');

    $this->shoot($page, 'package-inclusions');
});

test('package stay prices', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Dive Package')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.pricing.edit', [$this->property, $plan]), height: 1300)
        ->waitForText('Arrival days');

    $this->shoot($page, 'package-stay-prices', badges: ['Arrival days', 'Arrival dates']);
});
