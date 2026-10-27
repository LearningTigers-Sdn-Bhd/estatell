<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('change dates of a booking', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('S2')]))
        ->click('[aria-label="Unit actions"]')
        ->click('Change dates')
        ->waitForText('Save dates')
        ->click('#dates-departure_date')
        ->click('button[data-day="10/20/2026"]')
        ->wait(1);

    $this->shoot($page, 'change-dates', element: '[role="dialog"]');
});

test('extend a stay in house', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('S8')]))
        ->click('[aria-label="Unit actions"]')
        ->click('Change dates')
        ->waitForText('Save dates')
        ->click('#dates-departure_date')
        ->click('button[data-day="10/18/2026"]')
        ->wait(1);

    $this->shoot($page, 'extend-stay', element: '[role="dialog"]');
});

test('move room dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('S8')]), height: 1200)
        ->click('[aria-label="Unit actions"]')
        ->click('Move room')
        ->waitForText('Choose a room')
        ->click('Choose a room')
        ->click('[role="option"]:not(:has-text("101")) >> nth=0')
        ->type('[role="dialog"] textarea', 'Air-con not working in 101.')
        ->wait(1);

    $this->shoot($page, 'move-room', element: '[role="dialog"]');
});
