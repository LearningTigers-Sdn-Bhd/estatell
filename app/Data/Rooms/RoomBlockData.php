<?php

namespace App\Data\Rooms;

use App\Enums\RoomBlockReason;
use App\Models\RoomBlock;
use Spatie\LaravelData\Data;

/**
 * One row of the room blocks list, and the values of its edit form. Dates are `Y-m-d`; both nights are blocked.
 */
class RoomBlockData extends Data
{
    public function __construct(
        public int $id,
        public int $roomId,
        public string $roomNumber,
        public string $roomTypeName,
        public ?int $roomGroupId,
        public ?int $bedId,
        public ?string $bedLabel,
        public RoomBlockReason $reason,
        public string $startsOn,
        public string $endsOn,
        public ?string $notes,
        public ?string $completedOn,
    ) {}

    /**
     * Expects `room.roomType` and `bed` loaded. `completedOn` is the property's local date.
     */
    public static function fromModel(RoomBlock $block, string $timeZone): self
    {
        return new self(
            id: $block->id,
            roomId: $block->room_id,
            roomNumber: $block->room->number,
            roomTypeName: $block->room->roomType->name,
            roomGroupId: $block->room->room_group_id,
            bedId: $block->bed_id,
            bedLabel: $block->bed?->label,
            reason: $block->reason,
            startsOn: $block->starts_on->toDateString(),
            endsOn: $block->ends_on->toDateString(),
            notes: $block->notes,
            completedOn: $block->completed_at?->setTimezone($timeZone)->toDateString(),
        );
    }
}
