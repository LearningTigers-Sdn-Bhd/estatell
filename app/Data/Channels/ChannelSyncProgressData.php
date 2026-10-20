<?php

namespace App\Data\Channels;

use App\Enums\ChannelSyncState;
use Spatie\LaravelData\Data;

/**
 * Where the background structure sync of the property stands, for the admin Channels tab to poll. `waiting` is true
 * while an automatic sync is waiting after a change by staff.
 */
class ChannelSyncProgressData extends Data
{
    public function __construct(
        public ChannelSyncState $state,
        public int $done,
        public int $total,
        public ?string $message,
        public ?string $updatedAt,
        public bool $waiting,
    ) {}

    /**
     * @param  array{state: ChannelSyncState, done: int, total: int, message: string|null, updated_at: string|null}  $progress
     */
    public static function fromProgress(array $progress, bool $waiting = false): self
    {
        return new self($progress['state'], $progress['done'], $progress['total'], $progress['message'], $progress['updated_at'], $waiting);
    }
}
