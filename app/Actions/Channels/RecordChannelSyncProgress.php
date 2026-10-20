<?php

namespace App\Actions\Channels;

use App\Enums\ChannelSyncState;
use App\Services\Channels\ChannelSyncProgress;
use Illuminate\Support\Facades\Cache;

class RecordChannelSyncProgress
{
    /**
     * Keep where a property's background structure sync stands, for the admin tab to read. It is transient state in the
     * cache, not data, so it is not logged.
     */
    public function __invoke(int $propertyId, ChannelSyncState $state, int $done = 0, int $total = 0, ?string $message = null): void
    {
        Cache::put(
            ChannelSyncProgress::key($propertyId),
            ['state' => $state->value, 'done' => $done, 'total' => $total, 'message' => $message, 'updated_at' => now()->toIso8601String()],
            now()->addMinutes(ChannelSyncProgress::TTL_MINUTES),
        );
    }
}
