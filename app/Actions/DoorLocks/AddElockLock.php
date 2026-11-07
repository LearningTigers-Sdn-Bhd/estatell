<?php

namespace App\Actions\DoorLocks;

use App\Enums\LockProvider;
use App\Models\DoorLock;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;

/**
 * Staff add an eLock lock by hand: eLock has no cloud list to sync. `$lockNo` is the number the encoder writes
 * (`building.floor.room`). Returns false, writing nothing, when the number is already used at the property or the room is
 * another property's or already has a lock.
 */
class AddElockLock
{
    public function __invoke(User $actor, Property $property, string $name, string $lockNo, ?Room $room): DoorLock|false
    {
        if ($property->doorLocks()->where('lock_no', $lockNo)->exists()) {
            return false;
        }

        if ($room !== null && ($room->property_id !== $property->id || $property->doorLocks()->where('room_id', $room->id)->exists())) {
            return false;
        }

        $lock = $property->doorLocks()->create([
            'provider' => LockProvider::ELock,
            'lock_no' => $lockNo,
            'name' => $name,
            'room_id' => $room?->id,
            'synced_at' => now(),
        ]);

        activity()->performedOn($lock)->causedBy($actor)->event('door-locks.elock-added')
            ->withProperties(['property_id' => $property->id, 'lock_no' => $lockNo])->log('eLock lock added');

        return $lock;
    }
}
