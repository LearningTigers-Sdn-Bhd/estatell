<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('rooms list', function () {
    $page = $this->open(route('settings.rooms.index', $this->property), 1100)
        ->assertSee('First Floor');

    $this->shoot($page, 'rooms', badges: ['Generate rooms', 'New group', 'New room']);
});

test('generate rooms by range', function () {
    $page = $this->open(route('settings.rooms.index', $this->property), 720)
        ->click('Generate rooms')
        ->waitForText('Create up to');

    $page->click('#generate-room_type_id')
        ->click('[role="option"] >> text=Family Suite')
        ->type('#generate-from', '303')
        ->type('#generate-to', '306')
        ->type('#generate-prefix', 'F')
        ->waitForText('Create 4 rooms');

    $this->shoot($page, 'generate-range', frame: '[role="dialog"]');
});

test('generate rooms by floors', function () {
    $page = $this->open(route('settings.rooms.index', $this->property), 720)
        ->click('Generate rooms')
        ->waitForText('Create up to')
        ->click('Floors')
        ->click('#generate-room_type_id')
        ->click('[role="option"] >> text=Deluxe Twin')
        ->type('#generate-floor_from', '4')
        ->type('#generate-floor_to', '5')
        ->type('#generate-room_from', '01')
        ->type('#generate-room_to', '03')
        ->waitForText('Create 6 rooms');

    $this->shoot($page, 'generate-floors', frame: '[role="dialog"]');
});

test('generate rooms with a number in use', function () {
    $page = $this->open(route('settings.rooms.index', $this->property), 720)
        ->click('Generate rooms')
        ->waitForText('Create up to')
        ->click('#generate-room_type_id')
        ->click('[role="option"] >> text=Deluxe Twin')
        ->type('#generate-from', '207')
        ->type('#generate-to', '210')
        ->waitForText('already used');

    $this->shoot($page, 'generate-in-use', frame: '[role="dialog"]');
});

test('edit room dialog', function () {
    $page = $this->open(route('settings.rooms.index', $this->property))
        ->click('Edit')
        ->waitForText('Save changes');

    $this->shoot($page, 'edit-room', frame: '[role="dialog"]');
});

test('new room group dialog', function () {
    $page = $this->open(route('settings.rooms.index', $this->property))
        ->click('New group')
        ->waitForText('A group can be a floor');

    $this->shoot($page, 'new-group', frame: '[role="dialog"]');
});

test('archive room question', function () {
    $page = $this->open(route('settings.rooms.index', $this->property))
        ->click('Archive')
        ->waitForText('You can restore it later');

    $this->shoot($page, 'archive-room', frame: '[role="alertdialog"]');
});

test('ticked rooms', function () {
    $page = $this->open(route('settings.rooms.index', $this->property), 1100)
        ->assertSee('First Floor')
        ->click('[aria-label="Select room 104"]')
        ->click('[aria-label="Select room 105"]')
        ->waitForText('Change type');

    $this->shoot($page, 'rooms-ticked', badges: ['Change type', 'Archive']);
});

test('change type menu', function () {
    $page = $this->open(route('settings.rooms.index', $this->property), 1100)
        ->assertSee('First Floor')
        ->click('[aria-label="Select room 104"]')
        ->click('[aria-label="Select room 105"]')
        ->click('Change type')
        ->waitForText('Family Suite');

    $this->shoot($page, 'rooms-change-type');
});

test('map tab', function () {
    $page = $this->open(route('settings.rooms.index', [$this->property, 'view' => 'map']), 1000)
        ->waitForText('Shift-click paints every room between.');

    $page->page()->locator('label[for^="room-type-"]:has-text("Deluxe Twin")')->click();
    $page->page()->locator('button[aria-label^="Room 104,"]')->click();
    $page->page()->locator('button[aria-label^="Room 105,"]')->click(['modifiers' => ['Shift']]);
    $page->waitForText('2 rooms changed');
    $page->page()->locator('h4 >> nth=0')->hover();

    $this->shoot($page, 'rooms-map', badges: ['List', 'Map']);
});
