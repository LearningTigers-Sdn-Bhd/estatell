<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('the room view', function () {
    $page = $this->open(route('front-desk.rooms', $this->property), height: 600)
        ->waitForText('Vacant')
        ->wait(1);

    $this->shoot($page, 'room-view', badges: [
        'Arriving 2',
        'Dirty',
        'Departing',
        'In house',
        'New',
        'Block',
        'Clean',
        'Do not disturb',
    ]);
});

test('do not disturb', function () {
    $page = $this->open(route('front-desk.rooms', $this->property), height: 600)
        ->waitForText('Vacant')
        ->click('button:has-text("Do not disturb") >> nth=0')
        ->waitForText('Clear do not disturb')
        ->wait(1);

    $this->shoot($page, 'room-view-do-not-disturb', badges: ['Clear do not disturb']);
});

test('block a vacant room', function () {
    $page = $this->open(route('front-desk.rooms', $this->property), height: 900)
        ->waitForText('Vacant')
        ->click('button:has-text("Block") >> nth=0')
        ->waitForText('A blocked room or bed cannot be sold for these nights.')
        ->wait(1);

    $this->shoot($page, 'room-view-block', frame: '[role="dialog"]');
});

test('a blocked room', function () {
    $page = $this->open(route('front-desk.rooms', $this->property), height: 600)
        ->waitForText('Vacant')
        ->click('button:has-text("Block") >> nth=0')
        ->waitForText('A blocked room or bed cannot be sold for these nights.')
        ->click('[role="dialog"] button:has-text("Block room")')
        ->waitForText('Blocked 1')
        ->wait(6);

    $this->shoot($page, 'room-view-blocked', badges: ['Blocked']);
});
