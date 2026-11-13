<?php

namespace App\Actions\Rooms;

use App\Models\Bed;
use App\Models\Room;

/**
 * What a bulk room block Action did: how many rooms or blocks it changed, and the ones it skipped. A skip carries
 * the refusal when the single Action gives one (create), or null when the single Action only says no (end, cancel).
 */
class RoomBlockBulkResult
{
    /**
     * @param  list<array{room: Room, bed: Bed|null, refused: RoomBlockRefused|null}>  $skipped
     */
    public function __construct(
        public readonly int $done,
        public readonly array $skipped,
    ) {}
}
