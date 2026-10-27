<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('cancel unit dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->largestGroup()]))
        ->click('[aria-label="Unit actions"]')
        ->click('Cancel unit')
        ->waitForText('Cancellation policy');

    $this->shoot($page, 'cancel-unit', element: '[role="dialog"]');
});

test('cancel reservation dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->largestGroup()]))
        ->click('More')
        ->click('Cancel reservation')
        ->waitForText('Cancel 4 rooms');

    $this->shoot($page, 'cancel-reservation', element: '[role="dialog"]');
});

test('cancellation policy settings', function () {
    $this->shoot($this->openPolicy('cancellation', '100', '2'), 'cancellation-policy', highlight: ['a[href$="/settings/room-revenue"]', '[role="tab"][aria-selected="true"]']);
});
