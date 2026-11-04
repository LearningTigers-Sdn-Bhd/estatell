<?php

namespace App\Actions\ApiAccess;

use App\Models\ApiToken;

/**
 * Stamp `last_used_at`, at most once a minute so a busy kiosk does not write on every request.
 */
class RecordApiTokenUse
{
    public function __invoke(ApiToken $token): void
    {
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
