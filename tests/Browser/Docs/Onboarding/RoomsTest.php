<?php

beforeEach(function () {
    $this->startOnboarding();
});

test('room types', function () {
    $this->saveOnboardingBasics();

    $page = $this->open(route('onboarding.room-types.edit', $this->property->code), height: 800)
        ->waitForText('What kinds of rooms do you sell?')
        ->type('[aria-label="Name of room type 1"]', 'Standard Double')
        ->type('[aria-label="Max children of room type 1"]', '1')
        ->click('Add room type')
        ->type('[aria-label="Name of room type 2"]', 'Deluxe King');

    $this->shoot($page, 'room-types');
});

test('rooms with no groups', function () {
    $this->saveOnboardingRoomType();

    $page = $this->open(route('onboarding.rooms.edit', $this->property->code), height: 800)
        ->waitForText('Which rooms do you have?')
        ->type('[aria-label="First number of range 1"]', '101')
        ->type('[aria-label="Last number of range 1"]', '106')
        ->waitForText('101 … 106');

    $this->shoot($page, 'rooms');
});

test('rooms with groups', function () {
    $this->saveOnboardingRoomType();

    $page = $this->open(route('onboarding.rooms.edit', $this->property->code), height: 1000)
        ->waitForText('Which rooms do you have?')
        ->click('Use groups')
        ->type('[aria-label="Name of group 1"]', 'Ground floor')
        ->click('Add group')
        ->type('[aria-label="Name of group 2"]', 'Garden block')
        ->type('[aria-label="First number of range 1"]', '101')
        ->type('[aria-label="Last number of range 1"]', '103')
        ->waitForText('Ground floor 101 … 103');

    $this->shoot($page, 'rooms-groups');
});

test('saved rooms', function () {
    $this->saveOnboardingRooms($this->saveOnboardingRoomType());

    $page = $this->open(route('onboarding.rooms.edit', $this->property->code), height: 1100)
        ->waitForText('Saved rooms (5)')
        ->click('[aria-label="Remove room 101"]')
        ->waitForText('Remove room 101?');

    $this->shoot($page, 'rooms-saved');
});

test('room number already in use', function () {
    $this->saveOnboardingRooms($this->saveOnboardingRoomType());

    $page = $this->open(route('onboarding.rooms.edit', $this->property->code), height: 1100)
        ->waitForText('Saved rooms (5)')
        ->type('[aria-label="First number of range 1"]', '102')
        ->type('[aria-label="Last number of range 1"]', '104')
        ->waitForText('Already in use');

    $this->shoot($page, 'rooms-already-in-use');
});
