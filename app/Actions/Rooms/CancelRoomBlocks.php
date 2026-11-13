<?php

namespace App\Actions\Rooms;

use App\Models\RoomBlock;
use App\Models\User;
use Illuminate\Support\Collection;

class CancelRoomBlocks
{
    public function __construct(private CancelRoomBlock $cancelRoomBlock) {}

    /**
     * Cancel every block that has not started. It is not one transaction: a block that has started or ended is
     * skipped and the others are still cancelled. Expects each block's `room` and `bed` loaded.
     *
     * @param  Collection<int, RoomBlock>  $blocks
     */
    public function __invoke(User $actor, Collection $blocks): RoomBlockBulkResult
    {
        $done = 0;
        $skipped = [];

        foreach ($blocks as $block) {
            if (($this->cancelRoomBlock)($actor, $block)) {
                $done++;
            } else {
                $skipped[] = ['room' => $block->room, 'bed' => $block->bed, 'refused' => null];
            }
        }

        return new RoomBlockBulkResult($done, $skipped);
    }
}
