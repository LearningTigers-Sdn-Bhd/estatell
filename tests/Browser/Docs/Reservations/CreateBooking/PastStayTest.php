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

test('past day use form', function () {
    $this->signInAsScenariosOwner();

    $page = $this->open(route('reservations.past', $this->property), height: 1100)
        ->click('Day use')
        ->waitForText('Date and start')
        ->type('input[aria-label="Start time"]', '14:00')
        ->type('#booking-reason', 'Day use entered late')
        ->click('Select room type')
        ->click('Family Suite')
        ->click('#line-0-rate_plan_id')
        ->click('[role="option"] >> text=No plan · enter price')
        ->type('#booking-end-time', '17:30')
        ->waitForText('Ends 06:00 PM')
        ->click('#line-0-room_id')
        ->click('[role="listbox"] >> text=301')
        ->type('#override-total-0', '120.00')
        ->wait(1);
    $page->script('window.scrollTo(0, 0); document.querySelectorAll("main, [data-slot=sidebar-inset]").forEach((element) => element.scrollTo(0, 0))');

    $this->shoot($page, 'past-stay-day-use', badges: ['Stay type', 'Guest status', 'End', 'Reason']);
});
