<?php

namespace App\Actions\DoorLocks;

use App\Enums\PasscodeMode;
use App\Models\DoorLockConnection;
use App\Models\Property;
use App\Models\User;
use App\Services\DoorLocks\TtlockApi;

/**
 * Create or update the property's TTLock connection. A password signs in once to get the tokens and is never saved.
 * A blank password keeps the saved tokens, so the form never has to show them. Throws `TtlockException` when TTLock refuses the sign-in.
 */
class SaveDoorLockConnection
{
    public function __construct(private TtlockApi $api) {}

    /**
     * @param  array{ttlock_username: string, ttlock_password?: string|null, enabled: bool, passcode_mode: string}  $data
     */
    public function __invoke(Property $property, User $actor, array $data): DoorLockConnection
    {
        $connection = $property->doorLockConnection ?? new DoorLockConnection(['property_id' => $property->id]);
        $username = trim($data['ttlock_username']);
        $signedIn = filled($data['ttlock_password'] ?? null);

        if ($signedIn) {
            $tokens = $this->api->signIn($username, (string) $data['ttlock_password']);

            $connection->access_token = $tokens['access_token'];
            $connection->refresh_token = $tokens['refresh_token'];
            $connection->token_expires_at = now()->addSeconds($tokens['expires_in']);
            $connection->last_error = null;
        }

        $connection->forceFill([
            'property_id' => $property->id,
            'ttlock_username' => $username,
            'enabled' => $data['enabled'],
            'passcode_mode' => PasscodeMode::from($data['passcode_mode']),
        ])->save();

        activity()
            ->performedOn($connection)
            ->causedBy($actor)
            ->event('door-locks.connection-saved')
            ->withProperties(['property_id' => $property->id, 'enabled' => $connection->enabled, 'passcode_mode' => $connection->passcode_mode->value, 'signed_in' => $signedIn])
            ->log('Door lock connection saved');

        return $connection;
    }
}
