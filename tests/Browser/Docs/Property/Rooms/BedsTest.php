<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('beds in a dorm room', function () {
    $page = $this->open(route('settings.rooms.index', $this->property), 900)
        ->click('tr:has-text("D1") >> button:has-text("Beds")')
        ->waitForText('Beds in room D1');

    $this->shoot($page, 'beds-sheet', element: '[role="dialog"]');
});

test('add beds to every dorm room', function () {
    $page = $this->open(route('settings.rooms.index', $this->property), 560)
        ->click('Add beds')
        ->waitForText('Add beds to dorm rooms');

    $this->shoot($page, 'add-beds-to-all', element: '[role="dialog"]');
});
