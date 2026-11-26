<?php

namespace App\Data\Housekeeping;

use App\Data\Shared\BadgeData;
use App\Enums\HousekeepingReason;
use App\Enums\HousekeepingStatus;
use App\Enums\HousekeepingStay;
use App\Enums\Tone;
use App\Models\Room;
use Spatie\LaravelData\Data;

/**
 * One row of the housekeeping board. The server decides every field, including the next step the viewer may take.
 */
class HousekeepingRoomData extends Data
{
    /**
     * @param  list<HousekeepingBedBlockData>  $bedBlocks
     * @param  list<HousekeepingBedData>  $beds
     * @param  list<BadgeData>  $moves  The statuses the viewer may move this room to.
     * @param  list<HousekeepingBedData>  $beds  A dorm's active beds with their own status; empty for a room that sells rooms.
     */
    public function __construct(
        public int $id,
        public string $number,
        public ?int $roomGroupId,
        public ?string $roomGroupName,
        public int $roomTypeId,
        public string $roomTypeName,
        public BadgeData $status,
        public ?HousekeepingOutOfServiceData $outOfService,
        public array $bedBlocks,
        public array $beds,
        public array $moves,
        public ?int $assignedTo,
        public ?string $assignedName,
        public ?string $guest,
        public ?int $adults,
        public ?int $children,
        public ?string $arrivalDate,
        public ?string $arrivalTime,
        public ?string $departureDate,
        public ?string $departureTime,
        public bool $isStayOver,
        public ?BadgeData $stay,
        public bool $isPriority,
        public ?string $priorityNote,
        public ?string $remark,
        public ?string $guestRemark,
        public ?HousekeepingDormData $dorm,
        public bool $needsMaintenance,
        public bool $canDismissMaintenance,
        public bool $doNotDisturb,
        public ?string $reason,
        public RoomCleaningStateData $cleaning,
    ) {}

    /**
     * Expects `roomType`, `roomGroup` and `status.assignee` loaded.
     *
     * @param  list<HousekeepingBedBlockData>  $bedBlocks
     * @param  list<HousekeepingBedData>  $beds
     * @param  array{guest: ?string, adults: ?int, children: ?int, arrivalDate: ?string, arrivalTime: ?string, departureDate: ?string, departureTime: ?string, isStayOver: bool, stay: ?HousekeepingStay, guestRemark: ?string, counts: ?array{dueOut: int, checkedOut: int, arriving: int, arrived: int, inHouse: int, tonight: int, firstArrivalTime: ?string, lastDepartureTime: ?string}}  $stay
     */
    public static function forRoom(
        Room $room,
        ?HousekeepingOutOfServiceData $outOfService,
        array $bedBlocks,
        array $beds,
        array $stay,
        ?HousekeepingReason $reason,
        bool $canUpdate,
        bool $canInspect,
        bool $canBlock,
        string $date,
        RoomCleaningStateData $cleaning,
    ): self {
        $roomStatus = $room->status;
        $moves = $outOfService === null && $canUpdate
            ? array_map(fn (HousekeepingStatus $status): BadgeData => BadgeData::fromHousekeepingStatus($status), $roomStatus->status->movesFor($canInspect))
            : [];

        return new self(
            id: $room->id,
            number: $room->number,
            roomGroupId: $room->room_group_id,
            roomGroupName: $room->roomGroup?->name,
            roomTypeId: $room->room_type_id,
            roomTypeName: $room->roomType->name,
            status: $outOfService === null
                ? new BadgeData($roomStatus->status->value, $roomStatus->status->label(), $roomStatus->status->tone())
                : new BadgeData('out_of_service', __('Blocked'), Tone::Danger),
            outOfService: $outOfService,
            bedBlocks: $bedBlocks,
            beds: $beds,
            moves: $moves,
            assignedTo: $roomStatus->assigned_to,
            assignedName: $roomStatus->assignee?->name,
            guest: $stay['guest'],
            adults: $stay['adults'],
            children: $stay['children'],
            arrivalDate: $stay['arrivalDate'],
            arrivalTime: $stay['arrivalTime'],
            departureDate: $stay['departureDate'],
            departureTime: $stay['departureTime'],
            isStayOver: $stay['isStayOver'],
            stay: $stay['stay'] === null ? null : new BadgeData($stay['stay']->value, $stay['stay']->label(), $stay['stay']->tone()),
            isPriority: $roomStatus->is_priority,
            priorityNote: $roomStatus->priority_note,
            remark: $roomStatus->remark,
            // A dorm's beds carry their own guests' remarks.
            guestRemark: $beds === [] ? $stay['guestRemark'] : null,
            dorm: $beds === [] ? null : HousekeepingDormData::fromCounts($stay['counts'] ?? HousekeepingDormData::NO_COUNTS, count($beds)),
            needsMaintenance: $roomStatus->needs_maintenance,
            canDismissMaintenance: $canBlock && $roomStatus->needs_maintenance,
            doNotDisturb: $roomStatus->do_not_disturb_on?->toDateString() === $date,
            reason: $roomStatus->status === HousekeepingStatus::Dirty ? $reason?->label() : null,
            cleaning: $cleaning,
        );
    }
}
