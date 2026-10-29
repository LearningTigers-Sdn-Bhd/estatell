<?php

use App\Actions\Reservations\AssignRoom;
use App\Models\Room;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('undo check-out dialog', function () {
    // S13 is assigned S11's room for tonight when it is booked, and a taken room blocks the undo: move S13 to a free room.
    $arrival = $this->scenario('S13')->units()->firstOrFail();
    $rooms = Room::query()->where('room_type_id', $arrival->room_type_id)->whereKeyNot($arrival->nights()->value('room_id'))->get();
    $rooms->first(fn (Room $room): bool => ! app(AssignRoom::class)(auth()->user(), $arrival, $room)->isRefused());

    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('S11')]))
        ->click('More')
        ->click('Check out')
        ->waitForText('Collect and check out')
        ->click('[role="dialog"] button[type="submit"]')
        ->waitForText('Checked out')
        ->click('[aria-label="Unit actions"]')
        ->wait(1);

    $this->shoot($page, 'undo-check-out-menu', badges: ['Undo check-out']);

    $page->click('Undo check-out')
        ->waitForText('Folios that reopen')
        ->type('[role="dialog"]:has-text("Undo check-out") textarea', 'The guest is staying one more night.');

    $this->shoot($page, 'undo-check-out', frame: '[role="dialog"]:has-text("Undo check-out")');
});
