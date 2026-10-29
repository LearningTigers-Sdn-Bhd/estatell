<?php

use App\Models\RatePlan;

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('saved prices tab', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Half Board')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.saved-prices.show', [$this->property, $plan]), height: 1000)
        ->waitForText('Priced until');

    $this->shoot($page, 'saved-prices', badges: ['Today', '14 days']);
});

test('saved prices of a package', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Dive Package')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.saved-prices.show', [$this->property, $plan]), height: 800)
        ->waitForText('Garden Room');

    $this->shoot($page, 'saved-prices-package');
});
