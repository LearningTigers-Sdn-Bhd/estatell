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
        ->waitForText('Ends 02:00 PM')
        ->click('#line-0-room_id')
        ->click('[role="listbox"] >> text=101')
        ->waitForText('MYR 100.00')
        ->wait(1);
    $page->script('window.scrollTo(0, 0); document.querySelectorAll("main, [data-slot=sidebar-inset]").forEach((element) => element.scrollTo(0, 0))');

    $this->shoot($page, 'day-use-form', badges: ['Stay type', 'Date and start', 'Rate plan', 'Hours']);
});

test('day use with an entered price', function () {
    $page = $this->open(route('reservations.new', $this->property), height: 1100)
        ->click('Day use')
        ->waitForText('Date and start')
        ->click('Select room type')
        ->click('Garden Room')
        ->click('#line-0-rate_plan_id')
        ->click('[role="option"] >> text=No plan · enter price')
        ->type('#line-0-hours', '8')
        ->waitForText('Ends 06:00 PM')
        ->click('#line-0-room_id')
        ->click('[role="listbox"] >> text=101')
        ->type('#override-total-0', '150.00')
        ->wait(1);
    $page->script('window.scrollTo(0, 0); document.querySelectorAll("main, [data-slot=sidebar-inset]").forEach((element) => element.scrollTo(0, 0))');

    $this->shoot($page, 'day-use-entered-price', badges: ['Rate plan', 'Hours', 'Rate']);
});

test('walk-in day use that already checked out', function () {
    $page = $this->open(route('reservations.walk-in', $this->property), height: 1100)
        ->click('Day use')
        ->waitForText('Date and start')
        ->type('input[aria-label="Start time"]', '10:00')
        ->click('Select room type')
        ->click('Garden Room')
        ->click('Already checked out')
        ->type('#booking-end-time', '13:40')
        ->waitForText('Ends 02:00 PM')
        ->click('#line-0-room_id')
        ->click('[role="listbox"] >> text=101')
        ->type('#guest-0-full_name', 'Aisyah binti Rahman')
        ->waitForText('Room charges and tax')
        ->wait(1);
    $page->script('window.scrollTo(0, 0); document.querySelectorAll("main, [data-slot=sidebar-inset]").forEach((element) => element.scrollTo(0, 0))');

    $this->shoot($page, 'day-use-walk-in-checked-out', badges: ['Guest status', 'End', 'Payment']);
});

test('day use reservation page', function () {
    $reservation = Reservation::query()
        ->where('property_id', $this->property->id)
        ->whereHas('units', fn ($units) => $units->where('stay_type', StayType::DayUse))
        ->firstOrFail();

    $this->shoot($this->open(route('reservations.show', [$this->property, $reservation])), 'day-use-reservation');
});
