<?php

namespace App\Actions\BookingEngine;

use App\Models\NearbyPlace;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetPropertyNearbyPlaceActive
{
    /**
     * Show a place to guests or hide it. A hidden place keeps its description. A place turned on goes to the end of the
     * list. Returns false when the property does not have the place or it already has that state.
     */
    public function __invoke(User $actor, Property $property, NearbyPlace $place, bool $active): bool
    {
        return DB::transaction(function () use ($actor, $property, $place, $active): bool {
            $current = $property->nearbyPlaces()->whereKey($place->id)->lockForUpdate()->first();

            if ($current === null || $current->pivot->active === $active) {
                return false;
            }

            $attributes = ['active' => $active];

            if ($active) {
                $attributes['position'] = (int) $property->nearbyPlaces()->max('property_nearby_places.position') + 1;
            }

            $property->nearbyPlaces()->updateExistingPivot($place->id, $attributes);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event($active ? 'property.nearby_place_turned_on' : 'property.nearby_place_turned_off')
                ->withProperties(['nearby_place_id' => $place->id, 'name' => $place->name])
                ->log($active ? 'Nearby place turned on' : 'Nearby place turned off');

            return true;
        });
    }
}
