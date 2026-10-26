<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('past stay form', function () {
    $page = $this->open(route('reservations.past', $this->property))
        ->click('Pick a date')
        ->click('[role="dialog"] button:text-is("16")')
        ->type('#booking-reason', 'Walk-in entered late');

    $this->shoot($page, 'past-stay', badges: ['Arrival', 'Guest status', 'Reason']);
});
