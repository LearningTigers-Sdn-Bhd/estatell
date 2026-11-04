<?php

namespace App\Actions\ApiAccess;

use App\Models\ApiIdempotencyKey;

class PruneIdempotencyKeys
{
    /**
     * Delete answers older than the replay window. Returns how many rows were deleted. Running it again finds nothing more.
     */
    public function __invoke(): int
    {
        return ApiIdempotencyKey::query()->where('created_at', '<', now()->subHours(ApiIdempotencyKey::RETENTION_HOURS))->delete();
    }
}
