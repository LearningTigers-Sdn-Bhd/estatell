<?php

namespace App\Data\StayView;

use App\Enums\HousekeepingStatus;
use App\Enums\SellUnit;
use App\Models\Bed;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Stay View: a room, or one bed of a dorm room. `key` is `room-{id}` or `bed-{id}` and matches
 * `StaySegmentData::$rowKey`. Out of service is not here; it comes from the blocks.
 */
class RoomRowData extends Data
{
    public function __construct(
        public string $key,
        public int $roomId,
        public ?int $bedId,
        public string $roomNumber,
        public string $label,
        public ?int $roomGroupId,
        public ?string $roomGroupName,
        public int $roomTypeId,
        public string $roomTypeName,
        public SellUnit $sellUnit,
        public HousekeepingStatus $housekeepingStatus,
        public bool $isPriority,
        public bool $doNotDisturb,
    ) {}

    /**
     * Expects `roomType`, `roomGroup` and `status` loaded on the room. A bed row uses its room's housekeeping state.
     */
    public static function forRoom(Room $room, ?Bed $bed, CarbonImmutable $date): self
    {
        return new self(
            key: $bed === null ? 'room-'.$room->id : 'bed-'.$bed->id,
            roomId: $room->id,
            bedId: $bed?->id,
            roomNumber: $room->number,
            label: $bed === null ? $room->number : $bed->label,
            roomGroupId: $room->room_group_id,
            roomGroupName: $room->roomGroup?->name,
            roomTypeId: $room->room_type_id,
            roomTypeName: $room->roomType->name,
            sellUnit: $room->roomType->sell_unit,
            housekeepingStatus: $room->status->status,
            isPriority: $room->status->is_priority,
            doNotDisturb: $room->status->do_not_disturb_on?->toDateString() === $date->toDateString(),
        );
    }
}
