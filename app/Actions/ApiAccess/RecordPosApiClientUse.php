<?php

namespace App\Actions\ApiAccess;

use App\Models\PosApiClient;

/**
 * Stamp `last_used_at` on a POS client, at most once a minute.
 */
class RecordPosApiClientUse
{
    public function __invoke(PosApiClient $client): void
    {
        if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
