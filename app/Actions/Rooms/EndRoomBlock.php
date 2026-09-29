<?php

namespace App\Actions\Rooms;

use App\Enums\HousekeepingStatus;
use App\Models\RoomBlock;
use App\Models\User;
use App\Services\Property\PropertyToday;
use Illuminate\Support\Facades\DB;

class EndRoomBlock
{
    public function __construct(private PropertyToday $today) {}

    /**
     * Return a started block's room or bed to sale now. The row stays for history.
     *
     * For a whole-room block, `$status` (Clean or Dirty) becomes the room's housekeeping status, because only
     * staff know whether the work left the room ready. Bed blocks leave the room status as it is.
     *
     * Returns false when the block has not started yet (cancel it instead), has already ended, or is completed.
     */
    public function __invoke(User $actor, RoomBlock $block, ?HousekeepingStatus $status): bool
    {
        return DB::transaction(function () use ($actor, $block, $status): bool {
            $block = RoomBlock::query()->lockForUpdate()->findOrFail($block->id);
            $today = $this->today->date($block->property)->toDateString();

            if ($block->completed_at !== null || $block->starts_on->toDateString() > $today || $block->ends_on->toDateString() < $today) {
                return false;
            }

            $block->forceFill(['completed_at' => now()])->save();

            if ($block->bed_id === null && $status !== null) {
                $block->room->status()->update([
                    'status' => $status,
                    'last_changed_by' => $actor->id,
                    'last_changed_at' => now(),
                ]);
            }

            activity()
                ->performedOn($block)
                ->causedBy($actor)
                ->event('room_block.ended')
                ->withProperties(['property_id' => $block->property_id, 'room_status' => $block->bed_id === null ? $status?->value : null])
                ->log('Room block ended');

            return true;
        });
    }
}
