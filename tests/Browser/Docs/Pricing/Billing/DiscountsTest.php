<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('discounts list', function () {
    $page = $this->open(route('settings.discounts.index', $this->property))
        ->waitForText('New discount');

    $this->shoot($page, 'discounts', badges: ['Available', 'Discount', 'Code', 'Amount', 'Applies to', 'New discount']);
});

test('new discount sheet', function () {
    $page = $this->open(route('settings.discounts.index', $this->property), height: 900)
        ->click('New discount')
        ->waitForText('Set how much this discount takes off and which charges it applies to.')
        ->type('#discount-name', 'Returning guest')
        ->type('#discount-value', '10');

    $this->shoot($page, 'new-discount', frame: '[role="dialog"]');
});
