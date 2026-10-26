<?php

namespace App\Actions\DoorLocks;

use App\Models\DoorLockConnection;
use App\Models\User;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;

/**
 * Read the lock list to prove the tokens work. Saves `last_tested_at` or `last_error`. Returns the error, or null when it worked.
 */
class TestDoorLockConnection
{
    public function __construct(private TtlockApi $api, private FreshAccessToken $freshAccessToken) {}

    public function __invoke(DoorLockConnection $connection, User $actor): ?string
    {
        $error = null;

        try {
            $this->api->locks(($this->freshAccessToken)($connection));
        } catch (TtlockException $exception) {
            $error = $exception->getMessage();
        }

        $connection->forceFill($error === null ? ['last_tested_at' => now(), 'last_error' => null] : ['last_error' => $error])->save();

        activity()
            ->performedOn($connection)
            ->causedBy($actor)
            ->event('door-locks.connection-tested')
            ->withProperties(['property_id' => $connection->property_id, 'ok' => $error === null])
            ->log('Door lock connection tested');

        return $error;
    }
}
