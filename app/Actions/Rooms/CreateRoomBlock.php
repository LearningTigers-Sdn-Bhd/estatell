<?php

namespace App\Actions\Rooms;

use App\Actions\Housekeeping\SetNeedsMaintenance;
use App\Data\Rooms\RoomBlockInputData;
use App\Enums\RoomBlockRefusal;
use App\Events\InventoryChanged;
use App\Models\Bed;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use App\Services\Rooms\RoomAssignedNights;
use App\Services\Rooms\RoomBlockConflicts;
use Illuminate\Support\Facades\DB;

class CreateRoomBlock
{
    public function __construct(
        private RoomBlockConflicts $conflicts,
        private RoomAssignedNights $assignedNights,
        private SetNeedsMaintenance $setNeedsMaintenance,
    ) {}

    /**
     * Take a room, or one of its beds, out of sale for the given nights.
     *
     * Returns a refusal when the room or bed is archived, when an open block already covers any of the nights, or when
     * a stay holds any of them. A new block answers the room's "needs maintenance" flag, so the flag is cleared.
     *
     * @param  RoomBlockInputData  $data  The validated block fields.
     */
    public function __invoke(User $actor, Room $room, ?Bed $bed, RoomBlockInputData $data): RoomBlock|RoomBlockRefusal
    {
        return DB::transaction(function () use ($actor, $room, $bed, $data): RoomBlock|RoomBlockRefusal {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);

            if ($room->archived_at !== null || ($bed !== null && ($bed->room_id !== $room->id || $bed->archived_at !== null))) {
                return RoomBlockRefusal::Unavailable;
            }

            if ($this->conflicts->exist($room, $bed?->id, $data->startsOn, $data->endsOn)) {
                return RoomBlockRefusal::Blocked;
            }

            if ($this->assignedNights->existBetween($room, $bed?->id, $data->startsOn, $data->endsOn)) {
                return RoomBlockRefusal::HasStay;
            }

            $block = new RoomBlock([
                'reason' => $data->reason,
                'starts_on' => $data->startsOn,
                'ends_on' => $data->endsOn,
                'notes' => $data->notes,
            ]);
            $block->property_id = $room->property_id;
            $block->room_id = $room->id;
            $block->bed_id = $bed?->id;
            $block->created_by = $actor->id;
            $block->save();

            event(InventoryChanged::forBlock($block));

            activity()
                ->performedOn($block)
                ->causedBy($actor)
                ->event('room_block.created')
                ->withProperties([
                    'property_id' => $room->property_id,
                    'room_id' => $room->id,
                    'bed_id' => $bed?->id,
                    'reason' => $block->reason->value,
                    'starts_on' => $data->startsOn,
                    'ends_on' => $data->endsOn,
                ])
                ->log('Room block created');

            ($this->setNeedsMaintenance)($actor, $room, false);

            return $block;
        });
    }
}
