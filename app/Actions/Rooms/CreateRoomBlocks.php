<?php

namespace App\Actions\Rooms;

use App\Data\Rooms\RoomBlockInputData;
use App\Models\Bed;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Collection;

class CreateRoomBlocks
{
    public function __construct(private CreateRoomBlock $createRoomBlock) {}

    /**
     * Block every room for the same nights, reason and notes. It is not one transaction: a refused room (another
     * block, a stay, archived) is skipped and the others are still blocked. A bed is only given for a single room.
     *
     * @param  Collection<int, Room>  $rooms
     * @param  RoomBlockInputData  $data  The validated block fields.
     */
    public function __invoke(User $actor, Collection $rooms, ?Bed $bed, RoomBlockInputData $data): RoomBlockBulkResult
    {
        $done = 0;
        $skipped = [];

        foreach ($rooms as $room) {
            $result = ($this->createRoomBlock)($actor, $room, $bed, $data);

            if ($result instanceof RoomBlockRefused) {
                $skipped[] = ['room' => $room, 'bed' => $bed, 'refused' => $result];
            } else {
                $done++;
            }
        }

        return new RoomBlockBulkResult($done, $skipped);
    }
}
