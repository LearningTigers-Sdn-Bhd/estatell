<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
    $this->property->settings->update(['migration_window_days' => 365]);
});

test('migrated stay form', function () {
    $page = $this->open(route('reservations.migrated', $this->property))
        ->type('#booking-opening-amount', '1080.00')
        ->type('#booking-legacy-number', 'F1203')
        ->type('#booking-reason', 'Migrated from old PMS');

    $this->shoot($page, 'migrated-stay', badges: ['Guest status', 'Bring in the old folio', 'Reason']);
});

test('old stay form', function () {
    $page = $this->open(route('reservations.old', $this->property))
        ->type('#booking-reason', 'Stay before go-live, from old PMS');

    $this->shoot($page, 'old-stay');
});
