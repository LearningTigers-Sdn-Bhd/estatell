<?php

namespace App\Actions\DoorLocks;

use App\Enums\LockProvider;
use App\Models\DoorLock;
use App\Models\Room;
use App\Models\User;

/**
 * Staff change the name, number or room of an eLock lock. Returns false, writing nothing, for a TTLock lock, a number another
 * lock at the property already uses, or a room of another property or one that already has another lock. Cards already written
 * keep the old number: changing it does not touch them.
 */
class UpdateElockLock
{
    public function __invoke(User $actor, DoorLock $lock, string $name, string $lockNo, ?Room $room): bool
    {
        if ($lock->provider !== LockProvider::ELock) {
            return false;
        }

        $others = DoorLock::query()->where('property_id', $lock->property_id)->whereKeyNot($lock->id);

        if ((clone $others)->where('lock_no', $lockNo)->exists()) {
            return false;
        }

        if ($room !== null && ($room->property_id !== $lock->property_id || (clone $others)->where('room_id', $room->id)->exists())) {
            return false;
        }

        $lock->forceFill(['name' => $name, 'lock_no' => $lockNo, 'room_id' => $room?->id])->save();

        activity()->performedOn($lock)->causedBy($actor)->event('door-locks.elock-updated')
            ->withProperties(['property_id' => $lock->property_id, 'lock_no' => $lockNo, 'room_id' => $room?->id])->log('eLock lock updated');

        return true;
    }
}
