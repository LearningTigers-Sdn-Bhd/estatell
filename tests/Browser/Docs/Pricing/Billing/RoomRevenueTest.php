<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('room revenue taxes tab', function () {
    $page = $this->open(route('settings.room-revenue.index', $this->property), height: 1000)
        ->waitForText('Posting preview');

    $this->shoot($page, 'room-revenue');
});

test('room revenue day use tab', function () {
    $page = $this->open(route('settings.room-revenue.day-use.show', $this->property), height: 1000)
        ->waitForText('Taxes on day use');

    $this->shoot($page, 'room-revenue-day-use');
});
