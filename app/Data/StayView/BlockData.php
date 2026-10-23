<?php

namespace App\Data\StayView;

use App\Models\RoomBlock;
use Spatie\LaravelData\Data;

/**
 * An open room block in the window. A block with no bed takes the whole room, so every bed of a dorm room.
 * `firstNight` and `lastNight` are `Y-m-d`, both included.
 */
class BlockData extends Data
{
    public function __construct(
        public int $id,
        public int $roomId,
        public ?int $bedId,
        public string $firstNight,
        public string $lastNight,
        public string $reason,
        public string $reasonLabel,
    ) {}

    public static function fromModel(RoomBlock $block): self
    {
        return new self(
            id: $block->id,
            roomId: $block->room_id,
            bedId: $block->bed_id,
            firstNight: $block->starts_on->toDateString(),
            lastNight: $block->ends_on->toDateString(),
            reason: $block->reason->value,
            reasonLabel: $block->reason->label(),
        );
    }
}
