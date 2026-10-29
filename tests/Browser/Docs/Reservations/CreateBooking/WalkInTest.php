<?php

use App\Models\Tax;

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('walk-in form with a room and the payment', function () {
    $page = $this->open(route('reservations.walk-in', $this->property))
        ->click('Pick a date')
        ->click('[role="dialog"] button:text-is("16")')
        ->click('Select room type')
        ->click('Garden Room')
        ->click('Select rate plan')
        ->click('Bed & Breakfast')
        ->waitForText('MYR 480.00')
        ->click('#line-0-room_id')
        ->click('[role="listbox"] >> text=101')
        ->type('#guest-0-full_name', 'Aisyah binti Rahman')
        ->waitForText('Room charges and tax');

    $this->shoot($page, 'walk-in', badges: ['Reservation mode', 'Room / Bed', 'Payment']);
});

test('walk-in payment with the room charges unticked', function () {
    Tax::query()->where('property_id', $this->property->id)->where('code', 'TTX')->update(['collect_at_check_in' => true]);
    $page = $this->open(route('reservations.walk-in', $this->property))
        ->click('Pick a date')
        ->click('[role="dialog"] button:text-is("16")')
        ->click('Select room type')
        ->click('Garden Room')
        ->click('Select rate plan')
        ->click('Bed & Breakfast')
        ->waitForText('MYR 480.00')
        ->click('#line-0-room_id')
        ->click('[role="listbox"] >> text=101')
        ->type('#guest-0-full_name', 'Kenneth Tan')
        ->type('#guest-0-nationality_code', 'Singapore')
        ->click('[role="option"] >> text=Singapore')
        ->waitForText('Room charges and tax')
        ->click('label[for="walk-in-collect_room_charges"]')
        ->waitForText('Tourism tax');

    $this->shoot($page, 'walk-in-payment-unticked', element: 'aside');
});

test('walk-in day use payment for a guest who left', function () {
    $page = $this->open(route('reservations.walk-in', $this->property))
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
        ->waitForText('Room charges and tax');

    $this->shoot($page, 'walk-in-day-use-payment', element: 'aside');
});
