<?php

namespace App\Services\Channels;

use RuntimeException;
use Throwable;

/**
 * A structure sync stopped part-way. `partial` holds the Channex ids created or updated before it stopped, so the
 * caller can save them and the next run updates instead of creating duplicates.
 */
class ChannelStructureSyncFailed extends RuntimeException
{
    /**
     * @param  array{property_id: string|null, room_types: array<int, array{id: string, rate_plans: array<int, string>}>}  $partial
     */
    public function __construct(string $message, public readonly array $partial, public readonly bool $retryLater = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
