<?php

beforeEach(function () {
    $this->startOnboarding();
    $this->saveOnboardingTaxes();
});

test('charge items', function () {
    $page = $this->open(route('onboarding.charge-items.edit', $this->property->code), height: 1000)
        ->waitForText('Extras staff can charge')
        ->click('Add item')
        ->type('[aria-label="Name of item 6"]', 'Extra bed')
        ->type('tr:has([aria-label="Name of item 6"]) input[placeholder="e.g. 25.00"]', '30')
        ->wait(1);

    $this->shoot($page, 'charge-items');
});

test('charge item details', function () {
    $page = $this->open(route('onboarding.charge-items.edit', $this->property->code), height: 1000)
        ->waitForText('Extras staff can charge')
        ->click('[aria-label="More options for Laundry"]')
        ->waitForText('Staff can change');

    $this->shoot($page, 'charge-items-details');
});
