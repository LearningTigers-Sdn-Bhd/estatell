<?php

namespace App\Actions\Housekeeping;

use App\Enums\HousekeepingStatus;
use App\Models\BedStatus;
use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\User;

class SyncDormRoomStatus
{
    /**
     * Set a dorm's room status from its active beds: Dirty while any bed is Dirty, Inspected when every bed is, else
     * Clean. Everything that reads room status (the board, House status, auto-assign, the Concierge page) then sees the
     * dorm as its beds are. A room with no active bed is left as it is. See docs/19-housekeeping/04-dorm-beds.md.
     */
    public function __invoke(Room $room, ?User $actor): void
    {
        $statuses = BedStatus::query()
            ->whereHas('bed', fn ($beds) => $beds->where('room_id', $room->id)->active())
            ->pluck('status');

        if ($statuses->isEmpty()) {
            return;
        }

        $target = match (true) {
            $statuses->contains(HousekeepingStatus::Dirty) => HousekeepingStatus::Dirty,
            $statuses->every(fn (HousekeepingStatus $status): bool => $status === HousekeepingStatus::Inspected) => HousekeepingStatus::Inspected,
            default => HousekeepingStatus::Clean,
        };

        RoomStatus::query()
            ->where('room_id', $room->id)
            ->where('status', '!=', $target->value)
            ->update(['status' => $target->value, 'last_changed_by' => $actor?->id, 'last_changed_at' => now()]);
    }
}
