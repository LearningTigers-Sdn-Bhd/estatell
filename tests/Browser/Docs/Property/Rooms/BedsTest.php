<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('beds in a dorm room', function () {
    $page = $this->open(route('settings.rooms.index', $this->property), 900)
        ->hover('tr:has-text("D1") >> button:has-text("Beds")');

    $this->shoot($page, 'rooms-beds-button', badges: ['Beds']);

    $page->click('tr:has-text("D1") >> button:has-text("Beds")')
        ->waitForText('Beds in room D1');

    $this->shoot($page, 'beds-sheet', frame: '[role="dialog"]');
});

test('add beds to every dorm room', function () {
    $page = $this->open(route('settings.rooms.index', $this->property), 560);

    $this->shoot($page, 'rooms-add-beds-button', badges: ['Add beds']);

    $page->click('Add beds')
        ->waitForText('Add beds to dorm rooms');

    $this->shoot($page, 'add-beds-to-all', frame: '[role="dialog"]');
});
