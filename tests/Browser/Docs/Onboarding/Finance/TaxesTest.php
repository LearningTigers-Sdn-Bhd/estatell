<?php

beforeEach(function () {
    $this->startOnboarding();
    $this->saveOnboardingBasics();
});

test('taxes', function () {
    $page = $this->open(route('onboarding.taxes.edit', $this->property->code), height: 1000)
        ->waitForText('Your Malaysian taxes are ready')
        ->click('[aria-label="Charge Service Tax (SST)"]')
        ->click('[aria-label="Charge Tourism tax"]')
        ->waitForText('MYR 118.00');

    $this->shoot($page, 'taxes');
});

test('tourism tax details', function () {
    $page = $this->open(route('onboarding.taxes.edit', $this->property->code), height: 1000)
        ->waitForText('Your Malaysian taxes are ready')
        ->click('[aria-label="Charge Service Tax (SST)"]')
        ->click('[aria-label="Charge Tourism tax"]')
        ->click('[aria-label="More options for Tourism tax"]')
        ->waitForText('Foreign guests only')
        ->waitForText('MYR 118.00');

    $this->shoot($page, 'taxes-details');
});

test('tax that does not apply to rooms', function () {
    $page = $this->open(route('onboarding.taxes.edit', $this->property->code), height: 1000)
        ->waitForText('Your Malaysian taxes are ready')
        ->click('Add tax')
        ->type('[aria-label="Name of tax 3"]', 'Service charge')
        ->type('[aria-label="Rate of tax 3"]', '10')
        ->click('tr:nth-child(3) [role="checkbox"]')
        ->waitForText('Not applied to rooms')
        ->wait(1);

    $this->shoot($page, 'taxes-not-on-rooms');
});

test('no tax', function () {
    $page = $this->open(route('onboarding.taxes.edit', $this->property->code), height: 1000)
        ->waitForText('Your Malaysian taxes are ready')
        ->click('We don\'t charge tax');

    $this->shoot($page, 'taxes-none');
});
