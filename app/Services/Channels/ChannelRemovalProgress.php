<?php

namespace App\Services\Channels;

use App\Data\Channels\ChannelRemovalData;
use App\Enums\ChannelRemovalKind;
use App\Enums\ChannelRemovalState;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the archives and unlinks a staff member started that waited for the channel manager, newest last. They are kept
 * in the cache for 15 minutes by `RecordChannelRemovalProgress`, and shared with every page so the browser can show the
 * result as a toast and refresh the list. A pending one that has not moved for 15 minutes is dropped with the rest.
 */
class ChannelRemovalProgress
{
    public const TTL_MINUTES = 15;

    public static function key(int $userId): string
    {
        return "channel-removals:user:{$userId}";
    }

    /**
     * @return list<ChannelRemovalData>
     */
    public function forUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $entries = Cache::get(self::key($user->id), []);

        return array_values(array_map(
            fn (array $entry): ChannelRemovalData => new ChannelRemovalData(
                $entry['key'],
                ChannelRemovalKind::from($entry['kind']),
                $entry['name'],
                ChannelRemovalState::from($entry['state']),
                $entry['message'] ?? null,
                $entry['updated_at'],
            ),
            array_filter(is_array($entries) ? $entries : [], fn (mixed $entry): bool => is_array($entry) && now()->parse($entry['updated_at'])->gte(now()->subMinutes(self::TTL_MINUTES))),
        ));
    }
}
