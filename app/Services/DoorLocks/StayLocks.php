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
     * @return Collection<int, DoorLock>
     */
    public function for(ReservationUnit $unit): Collection
    {
        $roomIds = $unit->nights()->whereNotNull('room_id')->pluck('room_id')->unique();

        return DoorLock::query()->where('property_id', $unit->property_id)->whereIn('room_id', $roomIds)->with('property.doorLockConnection')->orderBy('id')->get();
    }
}
