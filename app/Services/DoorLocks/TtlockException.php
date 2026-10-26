<?php

namespace App\Services\DoorLocks;

use RuntimeException;

/**
 * An expected failure of TTLock or of the setup. The message is safe to show to staff.
 */
class TtlockException extends RuntimeException
{
    public static function notConnected(): self
    {
        return new self(__('Connect the TTLock account first.'));
    }

    public static function unreachable(): self
    {
        return new self(__('TTLock could not be reached. Try again in a moment.'));
    }

    public static function refused(int $code, string $message): self
    {
        return new self(match ($code) {
            -4043 => __('This lock does not allow remote unlock. Turn on "Remote unlock" in the lock settings of the TTLock app.'),
            -3009 => __('The lock memory is full. Delete an old PIN from the lock first.'),
            10004 => __('The TTLock sign-in has expired. Save the connection with the password again.'),
            default => __('TTLock refused the request (:code): :message', ['code' => $code, 'message' => $message]),
        });
    }
}
