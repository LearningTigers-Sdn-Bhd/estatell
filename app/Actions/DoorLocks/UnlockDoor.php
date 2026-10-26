<?php

namespace App\Actions\DoorLocks;

use App\Models\DoorLock;
use App\Models\User;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;

/**
 * Open a door from the screen. Needs a gateway: without one the action refuses and calls nothing.
 */
class UnlockDoor
{
    public function __construct(private TtlockApi $api, private FreshAccessToken $freshAccessToken) {}

    public function __invoke(DoorLock $lock, User $actor): DoorLockResult
    {
        $connection = $lock->property->doorLockConnection;

        if ($connection === null) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_NOT_CONNECTED);
        }

        if (! $lock->has_gateway) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_GATEWAY_REQUIRED);
        }

        try {
            $this->api->unlock(($this->freshAccessToken)($connection), $lock->ttlock_lock_id);
        } catch (TtlockException $exception) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_TTLOCK, $exception->getMessage());
        }

        activity()
            ->performedOn($lock)
            ->causedBy($actor)
            ->event('door-locks.unlocked')
            ->withProperties(['property_id' => $lock->property_id])
            ->log('Door unlocked from the screen');

        return DoorLockResult::done();
    }
}
