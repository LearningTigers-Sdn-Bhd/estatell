<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('rate calendar', function () {
    $page = $this->open(route('rates.calendar', $this->property->code), height: 1000)
        ->waitForText('Bulk edit');

    $this->shoot($page, 'calendar', badges: ['Today', 'Bulk edit', 'Month']);
});

test('legend', function () {
    $page = $this->open(route('rates.calendar', $this->property->code), height: 1000)
        ->waitForText('Bulk edit')
        ->click('[aria-label="Legend"]')
        ->waitForText('Closed to arrival');

    $this->shoot($page, 'legend');
});
