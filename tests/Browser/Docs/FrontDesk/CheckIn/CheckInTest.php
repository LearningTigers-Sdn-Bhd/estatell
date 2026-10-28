<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('check in dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->singleArrivalToday()]))
        ->click('More')
        ->wait(1);

    $this->shoot($page, 'check-in-menu', badges: ['Check in']);

    $page->click('Check in')
        ->waitForText('Collect now');

    $this->shoot($page, 'check-in', frame: '[role="dialog"]');
});
