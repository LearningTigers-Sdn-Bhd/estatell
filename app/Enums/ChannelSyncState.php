<?php

namespace App\Enums;

/**
 * Where a background structure sync of a property stands. It is kept for a short time in the cache, not in the
 * database: Idle means nothing is running and nothing finished lately.
 */
enum ChannelSyncState: string
{
    case Idle = 'idle';
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Idle => __('Idle'),
            self::Queued => __('Waiting to start'),
            self::Running => __('Syncing'),
            self::Done => __('Synced'),
            self::Failed => __('Failed'),
        };
    }

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
