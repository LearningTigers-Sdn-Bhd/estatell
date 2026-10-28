<?php

use App\Models\RatePlan;

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('rate plans list', function () {
    $page = $this->open(route('settings.rate-plans.index', $this->property))
        ->waitForText('Half Board');

    $this->shoot($page, 'rate-plans', badges: ['New rate plan', 'Sells', 'Pricing']);
});

test('rate plan editor tabs', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Half Board')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.edit', [$this->property, $plan]))
        ->waitForText('Save details');

    $this->shoot($page, 'editor-tabs', badges: ['Details', 'Room types', 'Pricing rules', 'Saved prices', 'Inclusions', 'Discounts', 'Companies']);
});

test('package plan tabs', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Dive Package')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.edit', [$this->property, $plan]))
        ->waitForText('Save details');

    $this->shoot($page, 'package-tabs', badges: ['Children', 'Inclusions', 'Companies']);
});

test('row menu', function () {
    $page = $this->open(route('settings.rate-plans.index', $this->property))
        ->waitForText('Half Board')
        ->click('[aria-label="Actions for Half Board"]')
        ->waitForText('Create derived plan');

    $this->shoot($page, 'row-menu', badges: ['Create derived plan']);
});

test('create derived plan sheet', function () {
    $page = $this->open(route('settings.rate-plans.index', $this->property))
        ->waitForText('Bed & Breakfast')
        ->click('[aria-label="Actions for Bed & Breakfast"]')
        ->click('Create derived plan')
        ->waitForText('Direction')
        ->type('#derived-plan-name', 'Member Rate')
        ->type('#derived-plan-value', '10')
        ->wait(1.5);

    $this->shoot($page, 'create-derived-plan', badges: ['Name', 'Direction', 'Value', 'Unit', "Today's price", 'Room types', 'Create plan'], frame: '[role="dialog"]');
});
