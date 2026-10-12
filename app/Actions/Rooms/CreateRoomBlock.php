<?php

namespace App\Actions\Rooms;

use App\Actions\Housekeeping\SetNeedsMaintenance;
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
     * @param  array{reason: string, starts_on: string, ends_on: string, notes: string|null}  $attributes  Validated block fields.
     */
    public function __invoke(User $actor, Room $room, ?Bed $bed, array $attributes): ?RoomBlock
    {
        return DB::transaction(function () use ($actor, $room, $bed, $attributes): ?RoomBlock {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);

            if ($room->archived_at !== null || ($bed !== null && ($bed->room_id !== $room->id || $bed->archived_at !== null))) {
                return null;
            }

            if ($this->conflicts->exist($room, $bed?->id, $attributes['starts_on'], $attributes['ends_on'])) {
                return null;
            }

            $block = new RoomBlock($attributes);
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
                    'starts_on' => $attributes['starts_on'],
                    'ends_on' => $attributes['ends_on'],
                ])
                ->log('Room block created');

            ($this->setNeedsMaintenance)($actor, $room, false);

            return $block;
        });
    }
}
