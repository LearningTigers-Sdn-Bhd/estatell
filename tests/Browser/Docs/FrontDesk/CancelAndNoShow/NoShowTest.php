<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('mark no-show dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->largestGroup()]))
        ->click('[aria-label="Unit actions"]')
        ->click('Mark no-show')
        ->waitForText('No-show policy');

    $this->shoot($page, 'mark-no-show', element: '[role="dialog"]');
});
