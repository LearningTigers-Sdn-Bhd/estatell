<?php

namespace App\Services\Pos;

use App\Models\PosQrSession;

/**
 * Whether a QR token can still take orders, and why not. Reads only.
 */
class QrSessionState
{
    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    public const LOCKED = 'locked';

    public function __invoke(PosQrSession $session): string
    {
        if ($session->revoked_at !== null) {
            return self::REVOKED;
        }

        if ($session->expires_at !== null && $session->expires_at->isPast()) {
            return self::EXPIRED;
        }

        if ($session->locked_until !== null && $session->locked_until->isFuture()) {
            return self::LOCKED;
        }

        return self::ACTIVE;
    }
}
