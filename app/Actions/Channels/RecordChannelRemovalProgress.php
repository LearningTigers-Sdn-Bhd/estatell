<?php

namespace App\Actions\Channels;

use App\Enums\ChannelRemovalKind;
use App\Enums\ChannelRemovalState;
use App\Services\Channels\ChannelRemovalProgress;
use Illuminate\Support\Facades\Cache;

class RecordChannelRemovalProgress
{
    /**
     * Keep where one archive or unlink of a staff member stands, for their pages to read. It is transient state in the
     * cache, so it is not logged. The latest 20 entries are kept.
     */
    public function __invoke(int $userId, ChannelRemovalKind $kind, int $subjectId, string $name, ChannelRemovalState $state, ?string $message = null): void
    {
        $key = ChannelRemovalProgress::key($userId);
        $entries = Cache::get($key, []);
        $entries = is_array($entries) ? $entries : [];
        $entryKey = "{$kind->value}:{$subjectId}";

        $entries[$entryKey] = [
            'key' => $entryKey.':'.now()->timestamp,
            'kind' => $kind->value,
            'name' => $name,
            'state' => $state->value,
            'message' => $message,
            'updated_at' => now()->toIso8601String(),
        ];

        Cache::put($key, array_slice($entries, -20, null, true), now()->addMinutes(ChannelRemovalProgress::TTL_MINUTES));
    }
}
