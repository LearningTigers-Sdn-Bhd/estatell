<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('check in dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->singleArrivalToday()]))
        ->click('More')
        ->click('Check in')
        ->waitForText('Collect now');

    $this->shoot($page, 'check-in', element: '[role="dialog"]');
});
