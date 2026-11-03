<?php

namespace App\Actions\BookingEngine;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderPropertyNearbyPlaces
{
    /**
     * Set the order of the property's places. Returns false unless the ids are exactly the places it has now.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(User $actor, Property $property, array $orderedIds): bool
    {
        return DB::transaction(function () use ($actor, $property, $orderedIds): bool {
            $existing = $property->nearbyPlaces()->lockForUpdate()->pluck('nearby_places.id')->all();

            if (count($orderedIds) !== count($existing) || array_diff($orderedIds, $existing) !== [] || array_diff($existing, $orderedIds) !== []) {
                return false;
            }

            foreach ($orderedIds as $index => $id) {
                $property->nearbyPlaces()->updateExistingPivot($id, ['position' => $index + 1]);
            }

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('property.nearby_places_reordered')
                ->log('Nearby places reordered');

            return true;
        });
    }
}
