<?php

namespace App\Actions\Rooms;

use App\Data\Rooms\RoomBlockInputData;
use App\Enums\RoomBlockRefusal;
use App\Events\InventoryChanged;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use App\Services\Rooms\RoomAssignedNights;
use App\Services\Rooms\RoomBlockConflicts;
use Illuminate\Support\Facades\DB;

class UpdateRoomBlock
{
    public function __construct(private RoomBlockConflicts $conflicts, private RoomAssignedNights $assignedNights) {}

    /**
     * Change the reason, nights or notes of an open block. The room and bed stay the same.
     *
     * Returns null when saved, or a refusal when the block has ended, when the new nights clash with another open block,
     * or when a stay holds any of them.
     *
     * @param  RoomBlockInputData  $data  The validated block fields.
     */
    public function __invoke(User $actor, RoomBlock $block, RoomBlockInputData $data): ?RoomBlockRefusal
    {
        return DB::transaction(function () use ($actor, $block, $data): ?RoomBlockRefusal {
            $room = Room::query()->lockForUpdate()->findOrFail($block->room_id);
            $block = RoomBlock::query()->lockForUpdate()->findOrFail($block->id);

            if ($block->completed_at !== null) {
                return RoomBlockRefusal::Ended;
            }

            if ($this->conflicts->exist($room, $block->bed_id, $data->startsOn, $data->endsOn, $block->id)) {
                return RoomBlockRefusal::Blocked;
            }

            if ($this->assignedNights->existBetween($room, $block->bed_id, $data->startsOn, $data->endsOn)) {
                return RoomBlockRefusal::HasStay;
            }

            $block->fill([
                'reason' => $data->reason,
                'starts_on' => $data->startsOn,
                'ends_on' => $data->endsOn,
                'notes' => $data->notes,
            ]);
            $changes = $block->getDirty();
            $previous = new InventoryChanged($block->property_id, $block->getOriginal('starts_on')->toDateString(), $block->getOriginal('ends_on')->toDateString(), $room->room_type_id);
            $block->save();
            event($previous);
            event(InventoryChanged::forBlock($block));

            activity()
                ->performedOn($block)
                ->causedBy($actor)
                ->event('room_block.updated')
                ->withProperties(['property_id' => $block->property_id, 'changes' => array_keys($changes)])
                ->log('Room block updated');

            return null;
        });
    }
}
