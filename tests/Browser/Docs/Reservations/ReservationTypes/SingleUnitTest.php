<?php

use App\Models\Reservation;

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('single unit reservation with the unit actions open', function () {
    $reservation = Reservation::query()
        ->where('property_id', $this->property->id)
        ->where('notes', 'like', 'Anniversary trip%')
        ->firstOrFail();

    $page = $this->open(route('reservations.show', [$this->property, $reservation]))
        ->click('[aria-label="Unit actions"]')
        ->waitForText('Mark no-show');

    $this->shoot($page, 'single-unit');
});
