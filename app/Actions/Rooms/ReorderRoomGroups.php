<?php

namespace App\Actions\Rooms;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderRoomGroups
{
    /**
     * Set the display order of the property's room groups.
     *
     * Returns false unless the ids are exactly the property's room groups.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(Property $property, User $actor, array $orderedIds): bool
    {
        return DB::transaction(function () use ($property, $actor, $orderedIds): bool {
            $current = $property->roomGroups()->lockForUpdate()->pluck('id')->all();

            if (count($orderedIds) !== count($current) || array_diff($orderedIds, $current) !== []) {
                return false;
            }

            foreach ($orderedIds as $index => $id) {
                $property->roomGroups()->whereKey($id)->update(['position' => $index + 1]);
            }

            activity()
                ->causedBy($actor)
                ->event('room_group.reordered')
                ->withProperties(['property_id' => $property->id, 'order' => $orderedIds])
                ->log('Room groups reordered');

            return true;
        });
    }
}
