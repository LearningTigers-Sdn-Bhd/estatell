<?php

use App\Actions\Rates\UpsertRates;
use App\Actions\Rooms\CancelRoomBlock;
use App\Actions\Rooms\CreateRoomBlock;
use App\Actions\Rooms\UpdateRoom;
use App\Data\Rooms\RoomBlockInputData;
use App\Enums\RoomBlockReason;
use App\Events\InventoryChanged;
use App\Events\RatesChanged;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Support/ChannelStructure.php';

test('saving rates tells the channels which plan, room type and dates changed', function () {
    Event::fake([RatesChanged::class]);
    $setup = channelAriSetup();
    $row = fn (string $date): array => ['room_type_id' => $setup['roomType']->id, 'date' => $date, 'amount' => '150.00', 'min_stay' => null, 'max_stay' => null, 'closed_to_arrival' => false, 'closed_to_departure' => false, 'stop_sell' => false];

    app(UpsertRates::class)($setup['ratePlan']->id, [$row('2026-10-20'), $row('2026-10-24')]);

    Event::assertDispatched(RatesChanged::class, fn (RatesChanged $event): bool => $event->propertyId === $setup['property']->id
        && $event->ratePlanId === $setup['ratePlan']->id
        && $event->roomTypeId === $setup['roomType']->id
        && $event->dateFrom === '2026-10-20'
        && $event->dateTo === '2026-10-24');
});

test('a room block tells the channels its nights, and cancelling it tells them again', function () {
    Event::fake([InventoryChanged::class]);
    $setup = channelAriSetup();
    $room = Room::query()->where('room_type_id', $setup['roomType']->id)->firstOrFail();
    $actor = User::factory()->create(['property_id' => $setup['property']->id]);

    $block = app(CreateRoomBlock::class)($actor, $room, null, new RoomBlockInputData(RoomBlockReason::Maintenance, '2026-10-20', '2026-10-22', null));
    app(CancelRoomBlock::class)($actor, $block);

    Event::assertDispatchedTimes(InventoryChanged::class, 2);
    Event::assertDispatched(InventoryChanged::class, fn (InventoryChanged $event): bool => $event->roomTypeId === $setup['roomType']->id && $event->dateFrom === '2026-10-20' && $event->dateTo === '2026-10-22');
});

test('moving a room to another room type tells the channels about both room types', function () {
    Event::fake([InventoryChanged::class]);
    $setup = channelAriSetup();
    $other = channelRoomType($setup['property'], 'Garden', 1);
    $room = Room::query()->where('room_type_id', $setup['roomType']->id)->firstOrFail();

    app(UpdateRoom::class)(User::factory()->create(['property_id' => $setup['property']->id]), $room, ['room_type_id' => $other->id]);

    Event::assertDispatched(InventoryChanged::class, fn (InventoryChanged $event): bool => $event->roomTypeId === $setup['roomType']->id);
    Event::assertDispatched(InventoryChanged::class, fn (InventoryChanged $event): bool => $event->roomTypeId === $other->id);
});
