<?php

namespace App\Actions\Rooms;

use App\Data\Rooms\RoomBulkResultData;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rooms\RoomTypeChangeGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChangeRoomsType
{
    public function __construct(
        private RoomTypeChangeGuard $guard,
        private UpdateRoom $updateRoom,
    ) {}

    /**
     * Move each room into an active room type. It is not one transaction: each room is locked and checked on its
     * own, so a refused room is listed and the others still change. A room already of the type is skipped and not
     * listed. See docs/05-rooms/02-rooms-and-groups.md.
     *
     * @param  Collection<int, Room>  $rooms
     */
    public function __invoke(User $actor, Collection $rooms, RoomType $roomType): RoomBulkResultData
    {
        $done = 0;
        $refused = [];

        foreach ($rooms as $room) {
            $changed = false;

            $refusal = DB::transaction(function () use ($actor, $room, $roomType, &$changed): ?string {
                $room = Room::query()->lockForUpdate()->findOrFail($room->id);

                if ($room->archived_at !== null) {
                    return __(':number is already archived.', ['number' => $room->number]);
                }

                if ($room->room_type_id === $roomType->id) {
                    return null;
                }

                $refusal = $this->guard->refusal($room, $roomType);

                if ($refusal !== null) {
                    return $refusal;
                }

                $changed = ($this->updateRoom)($actor, $room, ['room_type_id' => $roomType->id]);

                return null;
            });

            if ($refusal !== null) {
                $refused[] = $refusal;
            } elseif ($changed) {
                $done++;
            }
        }

        return new RoomBulkResultData($done, $refused);
    }
}
