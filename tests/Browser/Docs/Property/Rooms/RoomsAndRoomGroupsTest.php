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

    $this->shoot($page, 'generate-range', element: '[role="dialog"]');
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

    $this->shoot($page, 'generate-floors', element: '[role="dialog"]');
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

    $this->shoot($page, 'generate-in-use', element: '[role="dialog"]');
});

test('edit room dialog', function () {
    $page = $this->open(route('settings.rooms.index', $this->property))
        ->click('Edit')
        ->waitForText('Save changes');

    $this->shoot($page, 'edit-room', element: '[role="dialog"]');
});

test('new room group dialog', function () {
    $page = $this->open(route('settings.rooms.index', $this->property))
        ->click('New group')
        ->waitForText('A group can be a floor');

    $this->shoot($page, 'new-group', element: '[role="dialog"]');
});

test('archive room question', function () {
    $page = $this->open(route('settings.rooms.index', $this->property))
        ->click('Archive')
        ->waitForText('You can restore it later');

    $this->shoot($page, 'archive-room', element: '[role="alertdialog"]');
});
