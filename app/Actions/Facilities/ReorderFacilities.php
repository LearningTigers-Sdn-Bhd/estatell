<?php

namespace App\Actions\Facilities;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderFacilities
{
    /**
     * Set the order of the property's facilities, which the Timeline View also uses.
     *
     * Only available facilities are ordered, as Settings › Facilities lists them; unavailable ones keep their position
     * and are listed after them, like Charge items. Returns false, and saves nothing, unless the ids are exactly the
     * property's available facilities.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(Property $property, User $actor, array $orderedIds): bool
    {
        return DB::transaction(function () use ($property, $actor, $orderedIds): bool {
            $current = $property->facilities()->where('active', true)->lockForUpdate()->pluck('id')->all();

            if (count($orderedIds) !== count($current) || array_diff($orderedIds, $current) !== [] || array_diff($current, $orderedIds) !== []) {
                return false;
            }

            foreach ($orderedIds as $index => $id) {
                $property->facilities()->whereKey($id)->update(['position' => $index + 1]);
            }

            activity()
                ->causedBy($actor)
                ->event('facility.reordered')
                ->withProperties(['property_id' => $property->id, 'order' => $orderedIds])
                ->log('Facilities reordered');

            return true;
        });
    }
}
