<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('charge items list', function () {
    $page = $this->open(route('settings.charge-items.index', $this->property))
        ->waitForText('New charge item');

    $this->shoot($page, 'charge-items', badges: ['Available', 'Charge item', 'Code', 'Charged', 'Amount', 'Taxes', 'New charge item']);
});

test('new charge item dialog', function () {
    $page = $this->open(route('settings.charge-items.index', $this->property))
        ->click('New charge item')
        ->waitForText('You set the price and taxes on the next page.')
        ->type('#new-charge-item-name', 'Airport transfer');

    $this->shoot($page, 'new-charge-item', element: '[role="dialog"]');
});

test('charge item edit page', function () {
    $chargeItem = $this->property->chargeItems()->where('name', 'Laundry')->firstOrFail();

    $page = $this->open(route('settings.charge-items.edit', [$this->property, $chargeItem]), height: 1100)
        ->waitForText('Save charge item');

    $this->shoot($page, 'charge-item-edit');
});
