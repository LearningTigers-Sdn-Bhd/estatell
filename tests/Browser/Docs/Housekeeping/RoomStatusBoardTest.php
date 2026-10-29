<?php

use App\Enums\HousekeepingStatus;
use App\Enums\RoomBlockReason;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomStatus;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * Make the board show every status the page explains: room 103 departed (and a guest arrives), 101 is Inspected,
 * 106 is Clean, and 102 is blocked.
 */
$prepareBoard = function () {
    $rooms = Room::query()->where('property_id', $this->property->id);
    $room = fn (string $number): Room => (clone $rooms)->where('number', $number)->firstOrFail();

    ReservationUnit::query()
        ->whereIn('reservation_id', [$this->scenario('S11')->id])
        ->update(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()]);

    RoomStatus::query()->where('room_id', $room('101')->id)->update(['status' => HousekeepingStatus::Inspected]);
    RoomStatus::query()->where('room_id', $room('106')->id)->update(['status' => HousekeepingStatus::Clean]);

    RoomBlock::factory()->create([
        'room_id' => $room('102')->id,
        'reason' => RoomBlockReason::Maintenance,
        'starts_on' => '2026-10-14',
        'ends_on' => '2026-10-17',
        'notes' => 'Aircon',
    ]);
};

test('the room status board', function () use ($prepareBoard) {
    $prepareBoard->call($this);

    $page = $this->open(route('housekeeping.index', $this->property), 1150)
        ->waitForText('rooms on')
        ->click('button[aria-expanded]:has-text("Dorm")')
        ->click('button[aria-expanded]:has-text("Third Floor")')
        ->click('button[aria-expanded]:has-text("Second Floor")');

    $page->script('window.scrollTo(0, 0); document.querySelectorAll("*").forEach((node) => { node.scrollTop = 0; });');

    $this->shoot($page, 'room-status-board',
        badges: ['By room group', 'All statuses', 'Anyone', 'All rooms', 'Status', 'Notes'],
    );
});

test('the status menu of a clean room', function () use ($prepareBoard) {
    $prepareBoard->call($this);

    $page = $this->open(route('housekeeping.index', $this->property), 1000)
        ->waitForText('rooms on')
        ->click('[aria-label^="Change status of room 106"]')
        ->wait(1);

    $this->shoot($page, 'room-status-menu', frame: '[role="menu"]');
});
