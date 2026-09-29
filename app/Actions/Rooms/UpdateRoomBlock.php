<?php

namespace App\Actions\Rooms;

use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use App\Services\Rooms\RoomBlockConflicts;
use Illuminate\Support\Facades\DB;

class UpdateRoomBlock
{
    public function __construct(private RoomBlockConflicts $conflicts) {}

    /**
     * Change the reason, nights or notes of an open block. The room and bed stay the same.
     *
     * Returns false when the block has ended, or when the new nights clash with another open block.
     *
     * @param  array{reason: string, starts_on: string, ends_on: string, notes: string|null}  $attributes  Validated block fields.
     */
    public function __invoke(User $actor, RoomBlock $block, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $block, $attributes): bool {
            $room = Room::query()->lockForUpdate()->findOrFail($block->room_id);
            $block = RoomBlock::query()->lockForUpdate()->findOrFail($block->id);

            if ($block->completed_at !== null) {
                return false;
            }

            if ($this->conflicts->exist($room, $block->bed_id, $attributes['starts_on'], $attributes['ends_on'], $block->id)) {
                return false;
            }

            $block->fill($attributes);
            $changes = $block->getDirty();
            $block->save();

            activity()
                ->performedOn($block)
                ->causedBy($actor)
                ->event('room_block.updated')
                ->withProperties(['property_id' => $block->property_id, 'changes' => array_keys($changes)])
                ->log('Room block updated');

            return true;
        });
    }
}
