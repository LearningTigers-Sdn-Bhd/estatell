<?php

namespace App\Services\Housekeeping;

use App\Models\Room;
use App\Models\RoomBlock;
use Carbon\CarbonImmutable;

/**
 * A room is out of service while an open whole-room block covers the date. A bed block does not take the room out of
 * service. See docs/05-rooms/04-room-blocks-and-statuses.md.
 */
class RoomOutOfService
{
    public function block(Room $room, CarbonImmutable $date): ?RoomBlock
    {
        return RoomBlock::query()
            ->where('room_id', $room->id)
            ->whereNull('bed_id')
            ->open()
            ->overlapping($date, $date)
            ->orderBy('ends_on')
            ->first();
    }
}
