<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('change price dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->reservationByReference('PVR-26100017')]))
        ->click('tbody tr:first-child [data-slot="checkbox"]')
        ->click('Change price')
        ->waitForText('New price per night')
        ->fill('#night-price-price', '250.00')
        ->fill('#night-price-reason', 'Corporate rate agreed late')
        ->waitForText('Difference');

    $this->shoot($page, 'change-price', element: '[role="dialog"]');
});
