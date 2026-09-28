<?php

namespace App\Data\Rooms;

use App\Models\Room;
use Spatie\LaravelData\Data;

/**
 * One row of the rooms list, and the values of its edit form.
 */
class RoomData extends Data
{
    public function __construct(
        public int $id,
        public string $number,
        public int $roomTypeId,
        public ?int $roomGroupId,
        public bool $archived,
    ) {}

    public static function fromModel(Room $room): self
    {
        return new self(
            id: $room->id,
            number: $room->number,
            roomTypeId: $room->room_type_id,
            roomGroupId: $room->room_group_id,
            archived: $room->archived_at !== null,
        );
    }
}
