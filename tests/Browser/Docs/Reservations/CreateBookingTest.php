<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('new menu', function () {
    $page = $this->open(route('reservations.index', $this->property))
        ->click('New')
        ->assertSee('Walk-in');

    $this->shoot($page, 'new-menu', checkErrors: false);
});

test('new booking form', function () {
    $this->shoot($this->open(route('reservations.new', $this->property)), 'new-booking');
});

test('new booking form with a priced line', function () {
    $page = $this->open(route('reservations.new', $this->property))
        ->click('Pick a date')
        ->click('[role="dialog"] button:text-is("16")')
        ->click('Select room type')
        ->click('Garden Room')
        ->click('Select rate plan')
        ->click('Bed & Breakfast')
        ->waitForText('MYR 480.00');

    $this->shoot($page, 'new-booking-priced');
});
