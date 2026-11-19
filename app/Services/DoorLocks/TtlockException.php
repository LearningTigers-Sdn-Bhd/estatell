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

    public bool $duplicate = false;

    /** TTLock `errcode` of a refusal, or 0 for any other failure. */
    public int $ttlockCode = 0;

    /**
     * Whether asking again can change the answer. TTLock sends back nothing it can be asked again about (`unreachable`, a timeout, a gateway
     * that did not answer) as a code of 0 or as a code that is not listed here, so these are worth another try. A full lock, or a sign-in
     * that has expired, needs a person first.
     */
    public function isPermanent(): bool
    {
        return in_array($this->ttlockCode, [-3009, 10004], true);
    }

    public static function refused(int $code, string $message): self
    {
        $exception = new self(match ($code) {
            -3007 => __('That PIN is already used on this lock. Pick another.'),
            -4043 => __('This lock does not allow remote unlock. Turn on "Remote unlock" in the lock settings of the TTLock app.'),
            -3009 => __('The lock memory is full. Delete an old PIN from the lock first.'),
            10004 => __('The TTLock sign-in has expired. Save the connection with the password again.'),
            default => __('TTLock refused the request (:code): :message', ['code' => $code, 'message' => $message]),
        });
        $exception->duplicate = $code === -3007;
        $exception->ttlockCode = $code;

        return $exception;
    }
}
