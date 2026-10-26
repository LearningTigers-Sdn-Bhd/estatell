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

test('mark no-show dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->largestGroup()]))
        ->click('[aria-label="Unit actions"]')
        ->click('Mark no-show')
        ->waitForText('No-show policy');

    $this->shoot($page, 'mark-no-show', element: '[role="dialog"]');
});

test('cancel reservation dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->largestGroup()]))
        ->click('More')
        ->click('Cancel reservation')
        ->waitForText('Cancel 4 rooms');

    $this->shoot($page, 'cancel-reservation', element: '[role="dialog"]');
});
