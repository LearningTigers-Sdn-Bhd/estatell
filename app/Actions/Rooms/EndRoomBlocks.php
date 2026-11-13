<?php

namespace App\Actions\Rooms;

use App\Enums\HousekeepingStatus;
use App\Models\RoomBlock;
use App\Models\User;
use Illuminate\Support\Collection;

class EndRoomBlocks
{
    public function __construct(private EndRoomBlock $endRoomBlock) {}

    /**
     * End every block that is running today. It is not one transaction: a block that has not started or has ended is
     * skipped and the others still end. The room status is set for each whole-room block, as when one block ends.
     * Expects each block's `room` and `bed` loaded.
     *
     * @param  Collection<int, RoomBlock>  $blocks
     */
    public function __invoke(User $actor, Collection $blocks, ?HousekeepingStatus $status): RoomBlockBulkResult
    {
        $done = 0;
        $skipped = [];

        foreach ($blocks as $block) {
            if (($this->endRoomBlock)($actor, $block, $status)) {
                $done++;
            } else {
                $skipped[] = ['room' => $block->room, 'bed' => $block->bed, 'refused' => null];
            }
        }

        return new RoomBlockBulkResult($done, $skipped);
    }
}
