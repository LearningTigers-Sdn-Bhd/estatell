<?php

namespace App\Services\DoorLocks;

use App\Models\DoorLock;
use App\Models\ReservationUnit;
use Illuminate\Database\Eloquent\Collection;

/**
 * The door locks of the rooms a stay unit sleeps in, from its nights. It reads only.
 */
class StayLocks
{
    /**
     * The lock of the room the unit sleeps in now: the room of the latest night dated today or earlier, or of the first night when
     * none has begun. A PIN uses this, not `for()`. A guest who moves part way through keeps the first nights in the old room, and
     * a move planned for later has its later nights in the new room, so reading every night would leave the old room open and open
     * the new one too early. The PIN follows a move when staff move the unit (`StayRoomChanged`); a move planned for a later date
     * takes effect at the next sync, not on that date.
     *
     * @return Collection<int, DoorLock>
     */
    public function current(ReservationUnit $unit): Collection
    {
        $timeZone = $unit->property->settings->time_zone ?? 'Asia/Kuala_Lumpur';
        $nights = $unit->nights()->whereNotNull('room_id');
        $night = (clone $nights)->whereDate('date', '<=', now($timeZone)->toDateString())->orderByDesc('date')->first()
            ?? (clone $nights)->orderBy('date')->first();

        // No room on any night: nothing to open. `where('room_id', null)` would match every unlinked lock of the property.
        if ($night === null) {
            return new Collection;
        }

        return DoorLock::query()->where('property_id', $unit->property_id)->where('room_id', $night->room_id)->with('property.doorLockConnection')->orderBy('id')->get();
    }

    /**
     * Every lock of every room the unit sleeps in, for a card that must open them all (the kiosk).
     *
     * @return Collection<int, DoorLock>
     */
    public function for(ReservationUnit $unit): Collection
    {
        $roomIds = $unit->nights()->whereNotNull('room_id')->pluck('room_id')->unique();

        return DoorLock::query()->where('property_id', $unit->property_id)->whereIn('room_id', $roomIds)->with('property.doorLockConnection')->orderBy('id')->get();
    }
}
