<?php

namespace App\Actions\DoorLocks;

use App\Models\KeyportDevice;
use App\Models\User;

/**
 * Stop a Keyport PC from talking to Estatell. Its token answers 401 from now on. The actor is null when the PC
 * unpairs itself.
 */
class RevokeKeyportDevice
{
    public function __invoke(?User $actor, KeyportDevice $device): void
    {
        if ($device->revoked_at !== null) {
            return;
        }

        $device->forceFill(['revoked_at' => now()])->save();

        activity()
            ->performedOn($device)
            ->causedBy($actor)
            ->event('door-locks.keyport-revoked')
            ->withProperties(['property_id' => $device->property_id])
            ->log('Keyport computer removed');
    }
}
