<?php

namespace App\Actions\BookingEngine;

use App\Models\NearbyPlace;
use App\Models\Property;
use App\Models\User;

class RemovePropertyNearbyPlace
{
    /**
     * Take a place off the property. Returns false when the property does not have it.
     */
    public function __invoke(User $actor, Property $property, NearbyPlace $place): bool
    {
        if ($property->nearbyPlaces()->detach($place->id) === 0) {
            return false;
        }

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('property.nearby_place_removed')
            ->withProperties(['nearby_place_id' => $place->id, 'name' => $place->name])
            ->log('Nearby place removed');

        return true;
    }
}
