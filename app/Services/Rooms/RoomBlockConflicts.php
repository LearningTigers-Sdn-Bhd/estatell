<?php

namespace App\Services\Rooms;

use App\Models\Room;
use App\Models\RoomBlock;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds open blocks that clash with a planned block. Read only.
 *
 * A whole-room block clashes with any open block in the room. A bed block clashes with a whole-room block
 * and with a block on the same bed. Blocks on two different beds never clash.
 */
class RoomBlockConflicts
{
    /**
     * The earliest open block that clashes with the planned nights, or null.
     */
    public function first(Room $room, ?int $bedId, string $startsOn, string $endsOn, ?int $ignoreBlockId = null): ?RoomBlock
    {
        return $room->blocks()
            ->open()
            ->overlapping($startsOn, $endsOn)
            ->when($ignoreBlockId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreBlockId))
            ->when($bedId !== null, fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereNull('bed_id')->orWhere('bed_id', $bedId),
            ))
            ->orderBy('starts_on')
            ->orderBy('id')
            ->first();
    }
}
