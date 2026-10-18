<?php

namespace App\Data\Rooms;

use App\Models\Room;
use Spatie\LaravelData\Data;

/**
 * An active room of another room type that can move into the open room type.
 */
class RoomTypeRoomChoiceData extends Data
{
    public function __construct(
        public int $id,
        public string $number,
        public ?int $roomGroupId,
        public string $roomTypeName,
    ) {}

    public static function fromModel(Room $room): self
    {
        return new self(
            id: $room->id,
            number: $room->number,
            roomGroupId: $room->room_group_id,
            roomTypeName: $room->roomType->name,
        );
    }
}
