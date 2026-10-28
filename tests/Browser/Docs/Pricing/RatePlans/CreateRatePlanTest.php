<?php

use App\Models\RatePlan;

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('new rate plan sheet', function () {
    $page = $this->open(route('settings.rate-plans.index', $this->property));

    $this->shoot($page, 'new-rate-plan-button', badges: ['New rate plan']);

    $page->click('New rate plan')
        ->waitForText('Create rate plan')
        ->type('#rate-plan-name', 'Weekend Escape');

    $this->shoot($page, 'new-rate-plan', frame: '[role="dialog"]');
});

test('new package plan sheet', function () {
    $page = $this->open(route('settings.rate-plans.index', $this->property), height: 1100)
        ->click('New rate plan')
        ->waitForText('Create rate plan')
        ->type('#rate-plan-name', 'Island Escape')
        ->click('label[for="rate-plan-basis-package"]')
        ->waitForText('The stay is fixed');

    $this->shoot($page, 'new-package-plan', frame: '[role="dialog"]');
});

test('new day use plan sheet', function () {
    $page = $this->open(route('settings.rate-plans.index', $this->property), height: 1100)
        ->click('New rate plan')
        ->waitForText('Create rate plan')
        ->type('#rate-plan-name', 'Afternoon Rest')
        ->click('label[for="rate-plan-basis-day_use"]')
        ->waitForText('Hours sold');

    $this->shoot($page, 'new-day-use-plan', frame: '[role="dialog"]');
});

test('details tab', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Half Board')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.edit', [$this->property, $plan]))
        ->waitForText('Save details');

    $this->shoot($page, 'details-tab', badges: ['Name', 'Description', 'Extra adult', 'Extra child']);
});

test('day use details tab', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Day use')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.edit', [$this->property, $plan]), height: 1000)
        ->waitForText('Save details');

    $this->shoot($page, 'day-use-details', badges: ['Hours sold, from', 'Sold between, from']);
});

test('room types tab', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Half Board')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.room-types.edit', [$this->property, $plan]))
        ->waitForText('Save room types');

    $this->shoot($page, 'room-types-tab', badges: ['Garden Room']);
});
