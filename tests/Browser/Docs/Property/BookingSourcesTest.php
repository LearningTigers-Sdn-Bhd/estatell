<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('booking sources list', function () {
    $page = $this->open(route('settings.booking-sources.index', $this->property))
        ->assertSee('Booking source');

    $this->shoot($page, 'booking-sources', badges: ['Available', 'Used']);
});

test('new booking source dialog', function () {
    $page = $this->open(route('settings.booking-sources.index', $this->property))
        ->click('New booking source')
        ->waitForText('Where a booking came from');

    $this->shoot($page, 'new-booking-source', frame: '[role="dialog"]');
});

test('edit a system booking source dialog', function () {
    $page = $this->open(route('settings.booking-sources.index', $this->property))
        ->click('[aria-label="Edit Walk-in"]')
        ->waitForText('Edit booking source');

    $this->shoot($page, 'edit-system-booking-source', frame: '[role="dialog"]');
});
