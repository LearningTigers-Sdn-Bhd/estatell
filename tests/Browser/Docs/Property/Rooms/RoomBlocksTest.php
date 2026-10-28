<?php

use App\Actions\Rooms\CreateRoomBlock;
use App\Data\Rooms\RoomBlockInputData;
use App\Enums\RoomBlockReason;
use App\Models\Bed;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;

beforeEach(function () {
    $this->signInAsScenariosOwner();

    $owner = User::query()->where('email', 'owner@kinabalu-hostel.test')->firstOrFail();
    $create = app(CreateRoomBlock::class);

    $block = function (string $number, RoomBlockReason $reason, string $from, string $to, ?string $notes, ?string $bed = null) use ($owner, $create): void {
        $room = Room::query()->where('property_id', $this->property->id)->where('number', $number)->firstOrFail();
        $bedModel = $bed === null ? null : Bed::query()->where('room_id', $room->id)->where('label', $bed)->firstOrFail();

        $result = $create($owner, $room, $bedModel, new RoomBlockInputData($reason, $from, $to, $notes));

        expect($result)->toBeInstanceOf(RoomBlock::class, $number.' '.json_encode($result));
    };

    $block('302', RoomBlockReason::Maintenance, '2026-10-12', '2026-10-15', 'Air conditioner repair');
    $block('301', RoomBlockReason::OwnerUse, '2026-10-30', '2026-11-02', 'Owner family stays');
    $block('D1', RoomBlockReason::OutOfOrder, '2026-10-20', '2026-10-21', 'Broken bunk', 'A3');
});

test('room blocks list', function () {
    $page = $this->open(route('front-desk.room-blocks.index', $this->property))
        ->assertSee('Air conditioner repair');

    $this->shoot($page, 'room-blocks', badges: ['Block room', 'Reason', 'End now']);
});

test('block room dialog', function () {
    $page = $this->open(route('front-desk.room-blocks.index', $this->property))
        ->click('Block room')
        ->waitForText('A blocked room or bed');

    $this->shoot($page, 'block-room', frame: '[role="dialog"]');
});

test('end a block question', function () {
    $page = $this->open(route('front-desk.room-blocks.index', $this->property))
        ->click('End now')
        ->waitForText('Keep blocked');

    $this->shoot($page, 'end-block', frame: '[role="alertdialog"]');
});
