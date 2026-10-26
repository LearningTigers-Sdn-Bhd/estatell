<?php

use App\Enums\StayType;
use App\Models\Reservation;

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('day use booking form', function () {
    $page = $this->open(route('reservations.new', $this->property), height: 1100)
        ->click('Day use')
        ->waitForText('Date and start')
        ->click('Select room type')
        ->click('Garden Room')
        ->click('#line-0-hours')
        ->click('[role="option"] >> text=4 hours')
        ->waitForText('Ends 14:00')
        ->click('#line-0-room_id')
        ->click('[role="listbox"] >> text=101')
        ->waitForText('MYR 100.00')
        ->wait(1);
    $page->script('window.scrollTo(0, 0); document.querySelectorAll("main, [data-slot=sidebar-inset]").forEach((element) => element.scrollTo(0, 0))');

    $this->shoot($page, 'day-use-form', badges: ['Stay type', 'Date and start', 'Hours']);
});

test('day use reservation page', function () {
    $reservation = Reservation::query()
        ->where('property_id', $this->property->id)
        ->whereHas('units', fn ($units) => $units->where('stay_type', StayType::DayUse))
        ->firstOrFail();

    $this->shoot($this->open(route('reservations.show', [$this->property, $reservation])), 'day-use-reservation');
});
