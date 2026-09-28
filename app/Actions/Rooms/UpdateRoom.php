<?php

namespace App\Actions\Rooms;

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateRoom
{
    /**
     * Save changes to an active room. Only changed edits are logged.
     *
     * Returns false for an archived room.
     *
     * @param  array<string, mixed>  $attributes  Validated room fields.
     */
    public function __invoke(User $actor, Room $room, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $room, $attributes): bool {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);

            if ($room->archived_at !== null) {
                return false;
            }

            $room->fill($attributes);
            $changes = $room->getDirty();

            if ($changes === []) {
                return true;
            }

            $room->save();

            activity()
                ->performedOn($room)
                ->causedBy($actor)
                ->event('room.updated')
                ->withProperties(['property_id' => $room->property_id, 'changed' => array_keys($changes)])
                ->log('Room updated');

            return true;
        });
    }
}
