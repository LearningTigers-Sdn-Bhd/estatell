<?php

use App\Actions\Onboarding\SaveOnboardingBasicRate;
use App\Enums\PricingBasis;
use App\Enums\WeekendNights;

beforeEach(function () {
    $this->startOnboarding();
    $this->roomTypes = $this->saveOnboardingPriceableRoomTypes();
});

test('basic rate', function () {
    $page = $this->open(route('onboarding.basic-rate.edit', $this->property->code), height: 1000)
        ->waitForText('What does each room type cost per night?')
        ->click('[aria-label="Sell format"] button:nth-child(2)')
        ->waitForText('Weekend nights')
        ->click('[aria-label="Price by for Family Longhouse"]')
        ->click('[role="option"]:has-text("Guest")')
        ->type('tbody tr:nth-child(1) td:nth-child(3) input', '180')
        ->type('tbody tr:nth-child(1) td:nth-child(4) input', '220')
        ->type('tbody tr:nth-child(2) td:nth-child(3) input', '85')
        ->type('tbody tr:nth-child(2) td:nth-child(4) input', '95')
        ->waitForText('guest pays')
        ->waitForText('4 adults');

    $this->shoot($page, 'basic-rate', badges: ['Sell format', 'Weekend nights', 'Price by', 'Weekdays', 'Weekend (Fri, Sat)'], badgesFromLeft: 0);
});

test('basic rate every night', function () {
    $page = $this->open(route('onboarding.basic-rate.edit', $this->property->code), height: 800)
        ->waitForText('What does each room type cost per night?')
        ->type('tbody tr:nth-child(1) td:nth-child(3) input', '180')
        ->type('tbody tr:nth-child(2) td:nth-child(3) input', '200')
        ->waitForText('guest pays');

    $this->shoot($page, 'basic-rate-every-night', badges: ['Sell format', 'Every night'], badgesFromLeft: 0);
});

test('what the guest pays', function () {
    $page = $this->open(route('onboarding.basic-rate.edit', $this->property->code), height: 1000)
        ->waitForText('What does each room type cost per night?')
        ->click('[aria-label="Price by for Family Longhouse"]')
        ->click('[role="option"]:has-text("Guest")')
        ->type('tbody tr:nth-child(1) td:nth-child(3) input', '180')
        ->type('tbody tr:nth-child(2) td:nth-child(3) input', '85')
        ->waitForText('guest pays')
        ->waitForText('4 adults');

    $this->shoot($page, 'basic-rate-guest-pays', highlight: ['tbody tr:nth-child(1) td:nth-child(3) p', 'tbody tr:nth-child(2) td:nth-child(3) p']);
});

test('more rates', function () {
    $page = $this->open(route('onboarding.basic-rate.edit', $this->property->code), height: 1000)
        ->waitForText('What does each room type cost per night?')
        ->type('tbody tr:nth-child(1) td:nth-child(3) input', '180')
        ->type('tbody tr:nth-child(2) td:nth-child(3) input', '200')
        ->waitForText('guest pays')
        ->click('Add rate')
        ->type('[aria-label="Name of rate 1"]', 'Room with breakfast')
        ->type('[aria-label="Amount for rate 1"]', '20')
        ->wait(1);

    $this->shoot($page, 'more-rates', badges: ['Name', 'Price', 'Room types'], badgesFromLeft: 0);
});

test('more rates with own prices', function () {
    $page = $this->open(route('onboarding.basic-rate.edit', $this->property->code), height: 1100)
        ->waitForText('What does each room type cost per night?')
        ->type('tbody tr:nth-child(1) td:nth-child(3) input', '180')
        ->type('tbody tr:nth-child(2) td:nth-child(3) input', '200')
        ->waitForText('guest pays')
        ->click('Add rate')
        ->type('[aria-label="Name of rate 1"]', 'Long stay')
        ->click('[aria-label="Price of rate 1"]')
        ->click('[role="option"]:has-text("Own prices")')
        ->type('input[placeholder="e.g. 150.00"] >> nth=0', '150')
        ->type('input[placeholder="e.g. 150.00"] >> nth=1', '170')
        ->wait(1);

    $this->shoot($page, 'more-rates-own-prices', badges: ['Name', 'Price', 'Room types'], badgesFromLeft: 0);
});

test('switch price by on saved prices', function () {
    [$standard, $longhouse] = $this->roomTypes;

    app(SaveOnboardingBasicRate::class)($this->property, auth()->user(), WeekendNights::FriSat, true, [
        ['room_type_id' => $standard->id, 'price_by' => PricingBasis::PerRoom, 'weekday' => '180', 'weekend' => null],
        ['room_type_id' => $longhouse->id, 'price_by' => PricingBasis::PerRoom, 'weekday' => '200', 'weekend' => null],
    ]);

    $page = $this->open(route('onboarding.basic-rate.edit', $this->property->code), height: 800)
        ->waitForText('What does each room type cost per night?')
        ->click('[aria-label="Price by for Family Longhouse"]')
        ->click('[role="option"]:has-text("Guest")')
        ->waitForText('clears its prices');

    $this->shoot($page, 'basic-rate-switch-price-by', element: '[role="alertdialog"]');
});
