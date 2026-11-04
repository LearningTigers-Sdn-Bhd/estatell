<?php

namespace App\Actions\BookingEngine;

use App\Models\NearbyPlace;
use App\Models\Property;
use App\Models\User;

class UpdatePropertyNearbyPlace
{
    /**
     * Set the property's own name for the place and the short description the guest reads under it. An empty name, or
     * the shared name, keeps the place on the shared name. Returns false when the property does not have the place.
     */
    public function __invoke(User $actor, Property $property, NearbyPlace $place, ?string $displayName, ?string $description): bool
    {
        if (! $property->nearbyPlaces()->whereKey($place->id)->exists()) {
            return false;
        }

        $property->nearbyPlaces()->updateExistingPivot($place->id, [
            'display_name' => $displayName === $place->name ? null : $displayName,
            'description' => $description,
        ]);

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('property.nearby_place_updated')
            ->withProperties(['nearby_place_id' => $place->id, 'name' => $place->name])
            ->log('Nearby place updated');

        return true;
    }
}
