<?php

use App\Enums\HousekeepingStatus;
use App\Models\Room;
use App\Models\RoomStatus;

beforeEach(function () {
    $this->signInAsScenariosOwner();

    foreach (['101' => HousekeepingStatus::Dirty, '102' => HousekeepingStatus::Dirty, '103' => HousekeepingStatus::Clean] as $number => $status) {
        $room = Room::query()->where('property_id', $this->property->id)->where('number', $number)->firstOrFail();
        RoomStatus::query()->where('room_id', $room->id)->update(['status' => $status]);
    }
});

test('the status menu', function () {
    $page = $this->open(route('housekeeping.index', $this->property), height: 1000)
        ->waitForText('Housekeeping');

    $this->shoot($page, 'update-rooms-board', badges: ['Clean']);

    $page->click('[aria-label="Change status of room 103, now Clean"]')
        ->waitForText('Inspected');

    $this->shoot($page, 'update-rooms-status-menu', badges: ['Inspected'], frame: '[role="menu"]');
});

test('the bulk bar', function () {
    $page = $this->open(route('housekeeping.index', $this->property), height: 1000)
        ->waitForText('Housekeeping')
        ->click('[aria-label="Select room 101"]')
        ->click('[aria-label="Select room 102"]')
        ->click('[aria-label="Select room 103"]')
        ->waitForText('3 selected');

    $this->shoot($page, 'update-rooms-bulk-bar', badges: ['3 selected', 'Assign to', 'Mark clean', 'Mark inspected', 'More']);
});

test('the bulk result with skipped rooms', function () {
    $page = $this->open(route('housekeeping.index', $this->property), height: 1000)
        ->waitForText('Housekeeping')
        ->click('[aria-label="Select room 101"]')
        ->click('[aria-label="Select room 102"]')
        ->click('[aria-label="Select room 103"]')
        ->click('Mark inspected')
        ->waitForText('skipped');

    $page->script('document.querySelectorAll("*").forEach((el) => { el.scrollTop = 0; });');

    $this->shoot($page, 'update-rooms-bulk-result');
});
