<?php

namespace App\Services\DoorLocks;

use App\Enums\LockProvider;
use App\Models\DoorLock;
use App\Models\ReservationUnit;
use Illuminate\Database\Eloquent\Collection;

/**
 * The door locks of the rooms a stay unit sleeps in, from its nights. It reads only. `current()` and `for()` return TTLock locks, which
 * the PIN and card code drives; `currentElock()` returns the eLock locks, which only Keyport key cards open.
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
        return $this->inCurrentRoom($unit, LockProvider::TTLock);
    }

    /**
     * The eLock locks of the room the unit sleeps in now, chosen the same way as `current()`.
     *
     * @return Collection<int, DoorLock>
     */
    public function currentElock(ReservationUnit $unit): Collection
    {
        return $this->inCurrentRoom($unit, LockProvider::ELock);
    }

    /**
     * @return Collection<int, DoorLock>
     */
    private function inCurrentRoom(ReservationUnit $unit, LockProvider $provider): Collection
    {
        $timeZone = $unit->property->settings->time_zone ?? 'Asia/Kuala_Lumpur';
        $nights = $unit->nights()->whereNotNull('room_id');
        $night = (clone $nights)->whereDate('date', '<=', now($timeZone)->toDateString())->orderByDesc('date')->first()
            ?? (clone $nights)->orderBy('date')->first();

        // No room on any night: nothing to open. `where('room_id', null)` would match every unlinked lock of the property.
        if ($night === null) {
            return new Collection;
        }

        return DoorLock::query()->ofProvider($provider)->where('property_id', $unit->property_id)->where('room_id', $night->room_id)->with('property.doorLockConnection')->orderBy('id')->get();
    }

    /**
     * Every lock of every room the unit sleeps in, for a card that must open them all (the kiosk).
     *
     * @return Collection<int, DoorLock>
     */
    public function for(ReservationUnit $unit): Collection
    {
        $roomIds = $unit->nights()->whereNotNull('room_id')->pluck('room_id')->unique();

        return DoorLock::query()->ofProvider(LockProvider::TTLock)->where('property_id', $unit->property_id)->whereIn('room_id', $roomIds)->with('property.doorLockConnection')->orderBy('id')->get();
    }
}
