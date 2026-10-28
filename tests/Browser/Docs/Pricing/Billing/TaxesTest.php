<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('taxes list', function () {
    $page = $this->open(route('settings.taxes.index', $this->property))
        ->waitForText('New tax');

    $this->shoot($page, 'taxes', badges: ['Available', 'Tax', 'Applies to', 'Rate', 'Registration number', 'New tax']);
});

test('new tax sheet', function () {
    $page = $this->open(route('settings.taxes.index', $this->property), height: 900)
        ->click('New tax')
        ->waitForText('Configure how this tax applies and is calculated.')
        ->type('#tax-name', 'Service charge')
        ->type('#tax-code', 'SVC')
        ->type('#tax-rate', '10');

    $this->shoot($page, 'new-tax', frame: '[role="dialog"]');
});
