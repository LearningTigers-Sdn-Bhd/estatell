<?php

namespace App\Actions\Rooms;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderRoomTypes
{
    /**
     * Set the display order of the property's active room types.
     *
     * Returns false unless the ids are exactly the property's active room types.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(Property $property, User $actor, array $orderedIds): bool
    {
        return DB::transaction(function () use ($property, $actor, $orderedIds): bool {
            $active = $property->roomTypes()->active()->lockForUpdate()->pluck('id')->all();

            if (count($orderedIds) !== count($active) || array_diff($orderedIds, $active) !== []) {
                return false;
            }

            foreach (array_values($orderedIds) as $index => $id) {
                $property->roomTypes()->whereKey($id)->update(['position' => $index + 1]);
            }

            activity()
                ->causedBy($actor)
                ->event('room_type.reordered')
                ->withProperties(['property_id' => $property->id, 'order' => array_values($orderedIds)])
                ->log('Room types reordered');

            return true;
        });
    }
}
