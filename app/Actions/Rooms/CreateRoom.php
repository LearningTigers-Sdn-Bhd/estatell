<?php

namespace App\Actions\Rooms;

use App\Events\InventoryChanged;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateRoom
{
    /**
     * Add a room at the end of the list.
     *
     * @param  array<string, mixed>  $attributes  Validated room fields.
     */
    public function __invoke(Property $property, User $actor, array $attributes): Room
    {
        return DB::transaction(function () use ($property, $actor, $attributes): Room {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $room = new Room($attributes);
            $room->property_id = $property->id;
            $room->position = ((int) $property->rooms()->max('position')) + 1;
            $room->save();

            $status = new RoomStatus;
            $status->property_id = $property->id;
            $room->status()->save($status);

            event(InventoryChanged::forRoomType($property->id, $room->room_type_id));

            activity()
                ->performedOn($room)
                ->causedBy($actor)
                ->event('room.created')
                ->withProperties(['property_id' => $property->id, 'number' => $room->number])
                ->log('Room created');

            return $room;
        });
    }
}
