<?php

namespace App\Actions\Rooms;

use App\Data\Rooms\RoomBulkResultData;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Collection;

class ArchiveRooms
{
    public function __construct(private ArchiveRoom $archiveRoom) {}

    /**
     * Archive each room through ArchiveRoom, with the same rules as a single archive. A room already archived is
     * listed and the others still archive.
     *
     * @param  Collection<int, Room>  $rooms
     */
    public function __invoke(User $actor, Collection $rooms): RoomBulkResultData
    {
        $done = 0;
        $refused = [];

        foreach ($rooms as $room) {
            if (($this->archiveRoom)($actor, $room)) {
                $done++;
            } else {
                $refused[] = __(':number is already archived.', ['number' => $room->number]);
            }
        }

        return new RoomBulkResultData($done, $refused);
    }
}
