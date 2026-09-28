<?php

namespace App\Actions\Rooms;

use App\Models\RoomGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateRoomGroup
{
    /**
     * Rename a room group. Only changed edits are logged.
     *
     * @param  array<string, mixed>  $attributes  Validated room group fields.
     */
    public function __invoke(User $actor, RoomGroup $group, array $attributes): void
    {
        DB::transaction(function () use ($actor, $group, $attributes): void {
            $group = RoomGroup::query()->lockForUpdate()->findOrFail($group->id);

            $group->fill($attributes);
            $changes = $group->getDirty();

            if ($changes === []) {
                return;
            }

            $group->save();

            activity()
                ->performedOn($group)
                ->causedBy($actor)
                ->event('room_group.updated')
                ->withProperties(['property_id' => $group->property_id, 'changed' => array_keys($changes)])
                ->log('Room group updated');
        });
    }
}
