<?php

use App\Models\RatePlan;

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('discounts tab', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Half Board')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.discounts.edit', [$this->property, $plan]), height: 1300)
        ->waitForText('Save discounts');

    $this->shoot($page, 'discounts-tab', badges: ['Minimum nights', 'Discount', 'Value', 'From night', 'Add long-stay discount', 'Price preview']);
});

test('preview by stay length', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Half Board')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.discounts.edit', [$this->property, $plan]), height: 1300)
        ->waitForText('By stay length')
        ->click('By stay length')
        ->wait(1);

    $this->shoot($page, 'preview-by-stay-length', badges: ['By night', 'By stay length']);
});

test('no discounts yet', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Room Only')->firstOrFail();
    $plan->stayDiscounts()->delete();

    $page = $this->open(route('settings.rate-plans.discounts.edit', [$this->property, $plan]))
        ->waitForText('No long-stay discounts');

    $this->shoot($page, 'no-discounts');
});
