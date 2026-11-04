<?php

namespace App\Services\ApiAccess;

use App\Models\ApiIdempotencyKey;
use App\Models\ApiToken;

/**
 * Finds the stored first answer for an `Idempotency-Key`. Reads only. An answer older than 24 hours is not replayed.
 */
class IdempotentResponses
{
    public function find(ApiToken $token, string $key, string $path): ?ApiIdempotencyKey
    {
        return ApiIdempotencyKey::query()
            ->where('api_token_id', $token->id)
            ->where('key', $key)
            ->where('path', $path)
            ->where('created_at', '>', now()->subHours(ApiIdempotencyKey::RETENTION_HOURS))
            ->first();
    }
}
