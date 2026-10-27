<?php

namespace App\Actions\DoorLocks;

use App\Models\DoorLockConnection;
use App\Models\Property;
use App\Models\User;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;

/**
 * Pull the lock list from TTLock. A known lock keeps its room link and gets its name, battery and gateway flag refreshed.
 * A lock TTLock no longer lists stays here with an old `synced_at`. Returns the number of locks seen, or null with the error saved on the connection.
 */
class SyncDoorLocks
{
    public function __construct(private TtlockApi $api, private FreshAccessToken $freshAccessToken) {}

    public function __invoke(Property $property, DoorLockConnection $connection, User $actor): ?int
    {
        try {
            $locks = $this->api->locks(($this->freshAccessToken)($connection));
        } catch (TtlockException $exception) {
            $connection->forceFill(['last_error' => $exception->getMessage()])->save();

            return null;
        }

        foreach ($locks as $lock) {
            $property->doorLocks()->updateOrCreate(
                ['ttlock_lock_id' => $lock['id']],
                ['name' => $lock['name'], 'battery_percent' => $lock['battery'], 'has_gateway' => $lock['has_gateway'], 'synced_at' => now()],
            );
        }

        $connection->forceFill(['last_error' => null])->save();

        activity()
            ->performedOn($connection)
            ->causedBy($actor)
            ->event('door-locks.synced')
            ->withProperties(['property_id' => $property->id, 'locks' => count($locks)])
            ->log('Door locks synced');

        return count($locks);
    }
}
