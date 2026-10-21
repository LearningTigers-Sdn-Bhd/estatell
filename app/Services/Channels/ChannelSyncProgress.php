<?php

namespace App\Services\Channels;

use App\Actions\Channels\ScheduleChannelStructureSync;
use App\Enums\ChannelSyncState;
use Illuminate\Support\Facades\Cache;

/**
 * Reads where the background structure sync of a property stands. The state is kept in the cache for an hour by
 * `RecordChannelSyncProgress`, because it only matters while the admin watches. A sync that has not reported for
 * 15 minutes is taken as lost (a crashed worker), so the button works again.
 */
class ChannelSyncProgress
{
    public const TTL_MINUTES = 60;

    private const LOST_AFTER_MINUTES = 15;

    public static function key(int $propertyId): string
    {
        return "channel-structure-sync-progress:{$propertyId}";
    }

    /**
     * @return array{state: ChannelSyncState, done: int, total: int, message: string|null, updated_at: string|null}
     */
    public function forProperty(int $propertyId): array
    {
        $stored = Cache::get(self::key($propertyId));

        if (! is_array($stored)) {
            return $this->idle();
        }

        $state = ChannelSyncState::tryFrom((string) ($stored['state'] ?? '')) ?? ChannelSyncState::Idle;
        $updatedAt = $stored['updated_at'] ?? null;

        if ($state->isActive() && ($updatedAt === null || now()->parse($updatedAt)->lt(now()->subMinutes(self::LOST_AFTER_MINUTES)))) {
            return $this->idle();
        }

        return [
            'state' => $state,
            'done' => (int) ($stored['done'] ?? 0),
            'total' => (int) ($stored['total'] ?? 0),
            'message' => $stored['message'] ?? null,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Whether staff changed the structure and the automatic sync is waiting its couple of minutes.
     */
    public function isWaiting(int $propertyId): bool
    {
        return Cache::has(ScheduleChannelStructureSync::key($propertyId));
    }

    public function isActive(int $propertyId): bool
    {
        return $this->forProperty($propertyId)['state']->isActive();
    }

    /**
     * @return array{state: ChannelSyncState, done: int, total: int, message: string|null, updated_at: string|null}
     */
    private function idle(): array
    {
        return ['state' => ChannelSyncState::Idle, 'done' => 0, 'total' => 0, 'message' => null, 'updated_at' => null];
    }
}
