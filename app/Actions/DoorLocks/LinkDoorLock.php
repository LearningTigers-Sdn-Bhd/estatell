<?php

namespace App\Actions\DoorLocks;

use App\Models\DoorLock;
use App\Models\Room;
use App\Models\User;

/**
 * Link a lock to a room, or unlink it with a null room. Returns false, writing nothing, when the room belongs to
 * another property or already has another lock.
 */
class LinkDoorLock
{
    public function __invoke(User $actor, DoorLock $lock, ?Room $room): bool
    {
        if ($room !== null) {
            if ($room->property_id !== $lock->property_id) {
                return false;
            }

            if (DoorLock::query()->where('property_id', $lock->property_id)->where('room_id', $room->id)->whereKeyNot($lock->id)->exists()) {
                return false;
            }
        }

        $lock->forceFill(['room_id' => $room?->id])->save();

        activity()
            ->performedOn($lock)
            ->causedBy($actor)
            ->event('door-locks.lock-linked')
            ->withProperties(['property_id' => $lock->property_id, 'room_id' => $room?->id])
            ->log('Door lock linked');

        return true;
    }
}
