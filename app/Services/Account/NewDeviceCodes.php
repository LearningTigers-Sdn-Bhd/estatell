<?php

namespace App\Services\Account;

use App\Models\EmailOtp;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Reads the emailed new-device codes: how long they last and when the next one may be sent. See
 * docs/01-platform/09-new-device-check.md.
 */
class NewDeviceCodes
{
    /**
     * Minutes a code stays valid.
     */
    public const int VALID_MINUTES = 15;

    /**
     * Seconds before another code can be sent.
     */
    public const int RESEND_SECONDS = 60;

    /**
     * Wrong codes a pending code takes before it stops working and a new one must be sent.
     */
    public const int MAX_ATTEMPTS = 5;

    /**
     * Seconds until another code can be sent to the user. Zero when one can be sent now.
     */
    public function secondsUntilResend(User $user): int
    {
        $sentAt = EmailOtp::query()->whereBelongsTo($user)->value('created_at');

        if ($sentAt === null) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInSeconds(CarbonImmutable::parse($sentAt)->addSeconds(self::RESEND_SECONDS), false)));
    }
}
