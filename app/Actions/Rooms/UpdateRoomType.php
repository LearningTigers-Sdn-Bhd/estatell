<?php

namespace App\Actions\Rooms;

use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateRoomType
{
    /**
     * Save changes to an active room type.
     *
     * Returns false for an archived room type.
     *
     * @param  array<string, mixed>  $attributes  Validated room type fields.
     */
    public function __invoke(User $actor, RoomType $roomType, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $roomType, $attributes): bool {
            $roomType = RoomType::query()->lockForUpdate()->findOrFail($roomType->id);

            if ($roomType->archived_at !== null) {
                return false;
            }

            $roomType->fill($attributes);
            $changes = $roomType->getDirty();

            if ($changes === []) {
                return true;
            }

            $roomType->save();

            activity()
                ->performedOn($roomType)
                ->causedBy($actor)
                ->event('room_type.updated')
                ->withProperties(['property_id' => $roomType->property_id, 'changed' => array_keys($changes)])
                ->log('Room type updated');

            return true;
        });
    }
}
