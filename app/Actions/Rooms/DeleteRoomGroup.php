<?php

namespace App\Actions\Rooms;

use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteRoomGroup
{
    /**
     * Delete a room group that has no active rooms. Its archived rooms are left without a group.
     *
     * Returns false when the group still has active rooms.
     */
    public function __invoke(User $actor, RoomGroup $group): bool
    {
        return DB::transaction(function () use ($actor, $group): bool {
            $group = RoomGroup::query()->lockForUpdate()->findOrFail($group->id);

            if (Room::query()->where('room_group_id', $group->id)->active()->exists()) {
                return false;
            }

            $group->delete();

            activity()
                ->causedBy($actor)
                ->event('room_group.deleted')
                ->withProperties(['property_id' => $group->property_id, 'name' => $group->name])
                ->log('Room group deleted');

            return true;
        });
    }
}
