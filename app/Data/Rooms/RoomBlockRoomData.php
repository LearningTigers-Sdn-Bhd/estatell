<?php

namespace App\Data\Rooms;

use App\Enums\SellUnit;
use App\Models\Bed;
use App\Models\Room;
use Spatie\LaravelData\Data;

/**
 * An active room that can be blocked, with its active beds when its room type sells beds.
 */
class RoomBlockRoomData extends Data
{
    /** @param list<BedData> $beds */
    public function __construct(
        public int $id,
        public string $number,
        public string $roomTypeName,
        public ?int $roomGroupId,
        public bool $sellsBeds,
        public array $beds,
    ) {}

    /**
     * Expects `roomType` and the active, ordered `beds` loaded.
     */
    public static function fromModel(Room $room): self
    {
        $sellsBeds = $room->roomType->sell_unit === SellUnit::Bed;

        return new self(
            id: $room->id,
            number: $room->number,
            roomTypeName: $room->roomType->name,
            roomGroupId: $room->room_group_id,
            sellsBeds: $sellsBeds,
            beds: $sellsBeds ? array_values($room->beds->map(fn (Bed $bed): BedData => BedData::fromModel($bed))->all()) : [],
        );
    }
}
