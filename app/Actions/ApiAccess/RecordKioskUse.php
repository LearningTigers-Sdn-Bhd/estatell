<?php

namespace App\Actions\ApiAccess;

use App\Models\Kiosk;

/**
 * Stamp `last_used_at`, at most once a minute so a busy kiosk does not write on every request. It lives on the kiosk,
 * not the Sanctum token, so staff still see it after a revoke deletes the token.
 */
class RecordKioskUse
{
    public function __invoke(Kiosk $kiosk): void
    {
        if ($kiosk->last_used_at === null || $kiosk->last_used_at->lt(now()->subMinute())) {
            $kiosk->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
