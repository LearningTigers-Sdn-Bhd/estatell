<?php

use App\Models\RoomType;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('room types list', function () {
    $page = $this->open(route('settings.room-types.index', $this->property))
        ->assertSee('6-Bed Dorm');

    $this->shoot($page, 'room-types', badges: ['New room type', 'Sold by', 'Capacity']);
});

test('new room type sheet', function () {
    $page = $this->open(route('settings.room-types.index', $this->property))
        ->click('New room type')
        ->waitForText('Create room type');

    $this->shoot($page, 'new-room-type', frame: '[role="dialog"]');
});

test('room type details tab', function () {
    $roomType = RoomType::query()->where('property_id', $this->property->id)->where('name', 'Deluxe Twin')->firstOrFail();

    $page = $this->open(route('settings.room-types.edit', [$this->property, $roomType]))
        ->waitForText('Save details');

    $this->shoot($page, 'details-tab', badges: ['Details', 'Archive']);
});

test('room type rooms tab', function () {
    $roomType = RoomType::query()->where('property_id', $this->property->id)->where('name', 'Deluxe Twin')->firstOrFail();

    $page = $this->open(route('settings.room-types.rooms.edit', [$this->property, $roomType]))
        ->waitForText('Save rooms');

    $this->shoot($page, 'rooms-tab');
});

test('archive room type question', function () {
    $roomType = RoomType::query()->where('property_id', $this->property->id)->where('name', 'Family Suite')->firstOrFail();

    $page = $this->open(route('settings.room-types.edit', [$this->property, $roomType]))
        ->click('Archive')
        ->waitForText('You can restore it later');

    $this->shoot($page, 'archive', frame: '[role="alertdialog"]');
});
