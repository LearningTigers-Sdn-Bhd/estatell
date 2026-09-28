<?php

namespace App\Actions\Rooms;

use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArchiveRoomType
{
    /**
     * Hide a room type from new work. Existing records keep pointing at it.
     *
     * Returns false when it is already archived.
     */
    public function __invoke(User $actor, RoomType $roomType): bool
    {
        return DB::transaction(function () use ($actor, $roomType): bool {
            $roomType = RoomType::query()->lockForUpdate()->findOrFail($roomType->id);

            if ($roomType->archived_at !== null) {
                return false;
            }

            $roomType->forceFill(['archived_at' => now()])->save();

            activity()
                ->performedOn($roomType)
                ->causedBy($actor)
                ->event('room_type.archived')
                ->withProperties(['property_id' => $roomType->property_id])
                ->log('Room type archived');

            return true;
        });
    }
}
