<?php

namespace App\Actions\Rooms;

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArchiveRoom
{
    /**
     * Hide a room from new work. Existing records keep pointing at it.
     *
     * Returns false when it is already archived.
     */
    public function __invoke(User $actor, Room $room): bool
    {
        return DB::transaction(function () use ($actor, $room): bool {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);

            if ($room->archived_at !== null) {
                return false;
            }

            $room->forceFill(['archived_at' => now()])->save();

            activity()
                ->performedOn($room)
                ->causedBy($actor)
                ->event('room.archived')
                ->withProperties(['property_id' => $room->property_id])
                ->log('Room archived');

            return true;
        });
    }
}
