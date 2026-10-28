<?php

use App\Actions\FrontDesk\CheckInUnit;
use App\Models\Reservation;
use App\Services\Property\PropertyToday;

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('check out as day use dialog', function () {
    $reservation = Reservation::query()
        ->where('property_id', $this->property->id)
        ->where('notes', 'like', 'Arrives today.%')
        ->firstOrFail();
    $checkedInAt = app(PropertyToday::class)->now($this->property)->setTime(14, 5)->utc();
    $checkIn = app(CheckInUnit::class)(auth()->user(), $reservation->units()->firstOrFail(), withoutCard: true, asOf: $checkedInAt);
    expect($checkIn->isRefused())->toBeFalse();

    $page = $this->open(route('reservations.show', [$this->property, $reservation]), height: 1100)
        ->click('More')
        ->click('[role="menuitem"]:has-text("Check out as day use")')
        ->waitForText('Folio after check-out')
        ->type('[role="dialog"] input[type="time"] >> nth=1', '18:20')
        ->waitForText('Charged')
        ->wait(1);

    $this->shoot($page, 'check-out-as-day-use', element: '[role="dialog"]');
});
