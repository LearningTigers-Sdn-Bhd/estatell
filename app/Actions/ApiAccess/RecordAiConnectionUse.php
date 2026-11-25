<?php

namespace App\Actions\ApiAccess;

use App\Models\AiConnection;

/**
 * Stamp `last_used_at` on a chat API connection, at most once a minute.
 */
class RecordAiConnectionUse
{
    public function __invoke(AiConnection $connection): void
    {
        if ($connection->last_used_at === null || $connection->last_used_at->lt(now()->subMinute())) {
            $connection->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
