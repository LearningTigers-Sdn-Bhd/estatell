<?php

namespace App\Actions\Rooms;

use App\Events\InventoryChanged;
use App\Models\RoomBlock;
use App\Models\User;
use App\Services\Property\PropertyToday;
use Illuminate\Support\Facades\DB;

class CancelRoomBlock
{
    public function __construct(private PropertyToday $today) {}

    /**
     * Delete a block that has not started. The activity log keeps the record.
     *
     * Returns false when the block has started or is completed. A started block is ended instead.
     */
    public function __invoke(User $actor, RoomBlock $block): bool
    {
        return DB::transaction(function () use ($actor, $block): bool {
            $block = RoomBlock::query()->lockForUpdate()->findOrFail($block->id);

            if ($block->completed_at !== null || $block->starts_on->toDateString() <= $this->today->date($block->property)->toDateString()) {
                return false;
            }

            activity()
                ->causedBy($actor)
                ->event('room_block.cancelled')
                ->withProperties([
                    'property_id' => $block->property_id,
                    'room_id' => $block->room_id,
                    'bed_id' => $block->bed_id,
                    'reason' => $block->reason->value,
                    'starts_on' => $block->starts_on->toDateString(),
                    'ends_on' => $block->ends_on->toDateString(),
                ])
                ->log('Room block cancelled');

            event(InventoryChanged::forBlock($block));

            $block->delete();

            return true;
        });
    }
}
