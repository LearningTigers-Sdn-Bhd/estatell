<?php

namespace App\Actions\DoorLocks;

use App\Models\DoorLockConnection;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;

/**
 * The connection's access token, refreshed and saved when it ends within a day. Throws `TtlockException` when the account is not connected.
 */
class FreshAccessToken
{
    public function __construct(private TtlockApi $api) {}

    public function __invoke(DoorLockConnection $connection): string
    {
        if ($connection->access_token !== null && $connection->token_expires_at?->gt(now()->addDay())) {
            return $connection->access_token;
        }

        if ($connection->refresh_token === null) {
            throw TtlockException::notConnected();
        }

        $tokens = $this->api->refresh($connection->refresh_token);

        $connection->forceFill([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
        ])->save();

        return $tokens['access_token'];
    }
}
