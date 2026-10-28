<?php

beforeEach(function () {
    $this->startOnboarding();
    $this->saveOnboardingPriceableRoomTypes();
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

    $this->shoot($page, 'basic-rate');
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

    $this->shoot($page, 'more-rates');
});
