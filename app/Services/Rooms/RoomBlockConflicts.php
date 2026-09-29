<?php

namespace App\Services\Rooms;

use App\Models\Room;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds open blocks that clash with a planned block. Read only.
 *
 * A whole-room block clashes with any open block in the room. A bed block clashes with a whole-room block
 * and with a block on the same bed. Blocks on two different beds never clash.
 */
class RoomBlockConflicts
{
    public function exist(Room $room, ?int $bedId, string $startsOn, string $endsOn, ?int $ignoreBlockId = null): bool
    {
        return $room->blocks()
            ->open()
            ->overlapping($startsOn, $endsOn)
            ->when($ignoreBlockId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreBlockId))
            ->when($bedId !== null, fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereNull('bed_id')->orWhere('bed_id', $bedId),
            ))
            ->exists();
    }
}
