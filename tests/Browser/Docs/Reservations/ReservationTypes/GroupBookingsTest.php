<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('group reservation page', function () {
    $this->shoot($this->open(route('reservations.show', [$this->property, $this->largestGroup()])), 'group-reservation');
});

test('edit group dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->largestGroup()]))
        ->click('More')
        ->click('Edit group')
        ->waitForText('Who pays the room charges');

    $this->shoot($page, 'edit-group', frame: '[role="dialog"]');
});
