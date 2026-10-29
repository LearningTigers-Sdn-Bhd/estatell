<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('mark no-show dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->largestGroup()]))
        ->click('[aria-label="Unit actions"]')
        ->wait(1);

    $this->shoot($page, 'mark-no-show-menu', badges: ['Mark no-show']);

    $page->click('Mark no-show')
        ->waitForText('No-show policy');

    $this->shoot($page, 'mark-no-show', frame: '[role="dialog"]');
});

test('no-show policy settings', function () {
    $this->shoot($this->openPolicy('no-show', '100'), 'no-show-policy', highlight: ['a[href$="/settings/room-revenue"]', '[role="tab"][aria-selected="true"]']);
});
