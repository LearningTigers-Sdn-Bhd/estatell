<?php

namespace App\Actions\Housekeeping;

use App\Enums\HousekeepingStatus;
use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\User;

class MarkCleanedRoomClean
{
    public function __construct(private UpdateRoomStatus $updateRoomStatus) {}

    /**
     * A room whose cleaning just got its OUT time moves from Dirty to Clean. Clean and Inspected rooms stay as they
     * are, and a block that came up meanwhile leaves the status alone.
     */
    public function __invoke(User $actor, Room $room): void
    {
        if (RoomStatus::query()->where('room_id', $room->id)->where('status', HousekeepingStatus::Dirty->value)->exists()) {
            ($this->updateRoomStatus)($actor, $room, HousekeepingStatus::Clean);
        }
    }
}
