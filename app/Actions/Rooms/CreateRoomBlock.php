<?php

namespace App\Actions\Rooms;

use App\Actions\Housekeeping\SetNeedsMaintenance;
use App\Data\Rooms\RoomBlockInputData;
use App\Models\Bed;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use App\Services\Rooms\RoomBlockConflicts;
use Illuminate\Support\Facades\DB;

class CreateRoomBlock
{
    public function __construct(private RoomBlockConflicts $conflicts, private SetNeedsMaintenance $setNeedsMaintenance) {}

    /**
     * Take a room, or one of its beds, out of sale for the given nights.
     *
     * Returns null when the room or bed is archived, or when an open block already covers any of the nights. A new
     * block answers the room's "needs maintenance" flag, so the flag is cleared.
     *
     * @param  RoomBlockInputData  $data  The validated block fields.
     */
    public function __invoke(User $actor, Room $room, ?Bed $bed, RoomBlockInputData $data): ?RoomBlock
    {
        return DB::transaction(function () use ($actor, $room, $bed, $data): ?RoomBlock {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);

            if ($room->archived_at !== null || ($bed !== null && ($bed->room_id !== $room->id || $bed->archived_at !== null))) {
                return null;
            }

            if ($this->conflicts->exist($room, $bed?->id, $data->startsOn, $data->endsOn)) {
                return null;
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
