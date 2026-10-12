<?php

namespace App\Data\Housekeeping;

use App\Data\Shared\BadgeData;
use App\Enums\HousekeepingReason;
use App\Enums\HousekeepingStatus;
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
     * @param  list<BadgeData>  $moves  The statuses the viewer may move this room to.
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
        public array $moves,
        public ?int $assignedTo,
        public ?string $assignedName,
        public ?string $guest,
        public ?string $arrivalDate,
        public ?string $arrivalTime,
        public ?string $departureDate,
        public ?string $departureTime,
        public bool $isStayOver,
        public bool $isPriority,
        public ?string $priorityNote,
        public ?string $remark,
        public bool $needsMaintenance,
        public bool $canDismissMaintenance,
        public bool $doNotDisturb,
        public ?string $reason,
    ) {}

    /**
     * Expects `roomType`, `roomGroup` and `status.assignee` loaded.
     *
     * @param  list<HousekeepingBedBlockData>  $bedBlocks
     * @param  array{guest: ?string, arrivalDate: ?string, arrivalTime: ?string, departureDate: ?string, departureTime: ?string, isStayOver: bool}  $stay
     */
    public static function forRoom(
        Room $room,
        ?HousekeepingOutOfServiceData $outOfService,
        array $bedBlocks,
        array $stay,
        ?HousekeepingReason $reason,
        bool $canUpdate,
        bool $canInspect,
        bool $canBlock,
        string $date,
    ): self {
        $roomStatus = $room->status;
        $moves = [];

        if ($outOfService === null && $canUpdate) {
            foreach (HousekeepingStatus::cases() as $status) {
                if ($roomStatus->status->canTransitionTo($status) && ($status !== HousekeepingStatus::Inspected || $canInspect)) {
                    $moves[] = new BadgeData($status->value, $status->label(), $status->tone());
                }
            }
        }

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
            moves: $moves,
            assignedTo: $roomStatus->assigned_to,
            assignedName: $roomStatus->assignee?->name,
            guest: $stay['guest'],
            arrivalDate: $stay['arrivalDate'],
            arrivalTime: $stay['arrivalTime'],
            departureDate: $stay['departureDate'],
            departureTime: $stay['departureTime'],
            isStayOver: $stay['isStayOver'],
            isPriority: $roomStatus->is_priority,
            priorityNote: $roomStatus->priority_note,
            remark: $roomStatus->remark,
            needsMaintenance: $roomStatus->needs_maintenance,
            canDismissMaintenance: $canBlock && $roomStatus->needs_maintenance,
            doNotDisturb: $roomStatus->do_not_disturb_on?->toDateString() === $date,
            reason: $roomStatus->status === HousekeepingStatus::Dirty ? $reason?->label() : null,
        );
    }
}
