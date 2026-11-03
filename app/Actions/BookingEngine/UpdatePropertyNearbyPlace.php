<?php

namespace App\Actions\BookingEngine;

use App\Models\NearbyPlace;
use App\Models\Property;
use App\Models\User;

class UpdatePropertyNearbyPlace
{
    /**
     * Set the short description the guest reads under the place. Returns false when the property does not have the place.
     */
    public function __invoke(User $actor, Property $property, NearbyPlace $place, ?string $description): bool
    {
        if (! $property->nearbyPlaces()->whereKey($place->id)->exists()) {
            return false;
        }

        $property->nearbyPlaces()->updateExistingPivot($place->id, ['description' => $description]);

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('property.nearby_place_updated')
            ->withProperties(['nearby_place_id' => $place->id, 'name' => $place->name])
            ->log('Nearby place updated');

        return true;
    }
}
