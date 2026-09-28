<?php

namespace App\Actions\Rooms;

use App\Models\Property;
use App\Models\RoomGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateRoomGroup
{
    /**
     * Add a room group at the end of the list.
     *
     * @param  array<string, mixed>  $attributes  Validated room group fields.
     */
    public function __invoke(Property $property, User $actor, array $attributes): RoomGroup
    {
        return DB::transaction(function () use ($property, $actor, $attributes): RoomGroup {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $group = new RoomGroup($attributes);
            $group->property_id = $property->id;
            $group->position = ((int) $property->roomGroups()->max('position')) + 1;
            $group->save();

            activity()
                ->performedOn($group)
                ->causedBy($actor)
                ->event('room_group.created')
                ->withProperties(['property_id' => $property->id, 'name' => $group->name])
                ->log('Room group created');

            return $group;
        });
    }
}
