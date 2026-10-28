<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('check in all dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->largestGroup()]))
        ->click('More')
        ->wait(1);

    $this->shoot($page, 'check-in-all-menu', badges: ['Check in all']);

    $page->click('Check in all')
        ->waitForText('Check in 4 units');

    $this->shoot($page, 'check-in-all', frame: '[role="dialog"]');
});
