<?php

namespace App\Actions\Rooms;

use App\Events\InventoryChanged;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreRoom
{
    /**
     * Bring an archived room back at the end of the list.
     *
     * Returns false when it is not archived, or an active room already has the same number.
     */
    public function __invoke(User $actor, Room $room): bool
    {
        return DB::transaction(function () use ($actor, $room): bool {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);

            if ($room->archived_at === null) {
                return false;
            }

            $numberTaken = Room::query()
                ->where('property_id', $room->property_id)
                ->active()
                ->whereRaw('lower(number) = ?', [mb_strtolower($room->number)])
                ->exists();

            if ($numberTaken) {
                return false;
            }

            $room->forceFill([
                'archived_at' => null,
                'position' => ((int) Room::query()->where('property_id', $room->property_id)->max('position')) + 1,
            ])->save();

            event(InventoryChanged::forRoomType($room->property_id, $room->room_type_id));

            activity()
                ->performedOn($room)
                ->causedBy($actor)
                ->event('room.restored')
                ->withProperties(['property_id' => $room->property_id])
                ->log('Room restored');

            return true;
        });
    }
}
