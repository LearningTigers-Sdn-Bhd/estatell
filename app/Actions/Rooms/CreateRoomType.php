<?php

namespace App\Actions\Rooms;

use App\Actions\Rates\ReconcileRoomTypeRatePlans;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateRoomType
{
    public function __construct(private ReconcileRoomTypeRatePlans $reconcileRatePlans) {}

    /**
     * Add a room type at the end of the list. It is linked to the first active rate plan whose pricing basis fits.
     *
     * @param  array<string, mixed>  $attributes  Validated room type fields.
     */
    public function __invoke(Property $property, User $actor, array $attributes): RoomType
    {
        return DB::transaction(function () use ($property, $actor, $attributes): RoomType {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $roomType = new RoomType($attributes);
            $roomType->property_id = $property->id;
            $roomType->position = ((int) $property->roomTypes()->max('position')) + 1;
            $roomType->save();

            ($this->reconcileRatePlans)($roomType);

            activity()
                ->performedOn($roomType)
                ->causedBy($actor)
                ->event('room_type.created')
                ->withProperties(['property_id' => $property->id, 'name' => $roomType->name])
                ->log('Room type created');

            return $roomType;
        });
    }
}
