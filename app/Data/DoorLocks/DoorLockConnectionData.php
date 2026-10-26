<?php

namespace App\Data\DoorLocks;

use App\Models\DoorLockConnection;
use Spatie\LaravelData\Data;

/**
 * The connection without its tokens: the browser only learns whether the account is signed in.
 */
class DoorLockConnectionData extends Data
{
    public function __construct(
        public string $ttlockUsername,
        public bool $signedIn,
        public bool $enabled,
        public string $passcodeMode,
        public ?string $lastTestedAt,
        public ?string $lastError,
    ) {}

    public static function fromModel(DoorLockConnection $connection): self
    {
        return new self(
            ttlockUsername: $connection->ttlock_username,
            signedIn: filled($connection->refresh_token),
            enabled: $connection->enabled,
            passcodeMode: $connection->passcode_mode->value,
            lastTestedAt: $connection->last_tested_at?->toIso8601String(),
            lastError: $connection->last_error,
        );
    }
}
