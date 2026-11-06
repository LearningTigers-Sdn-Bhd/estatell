<?php

namespace App\Actions\BookingEngine;

use App\Models\NearbyPlace;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LinkSuggestedNearbyPlace
{
    /**
     * Add an Approved place from the suggestions. Returns false, and writes nothing, when the place is not Approved or the
     * property already has it.
     */
    public function __invoke(User $actor, Property $property, NearbyPlace $place): bool
    {
        return DB::transaction(function () use ($actor, $property, $place): bool {
            $approved = NearbyPlace::query()->approved()->whereKey($place->id)->exists();

            if (! $approved || $property->nearbyPlaces()->whereKey($place->id)->exists()) {
                return false;
            }

            $position = (int) $property->nearbyPlaces()->max('property_nearby_places.position') + 1;
            $property->nearbyPlaces()->attach($place->id, ['position' => $position]);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('property.nearby_place_added')
                ->withProperties(['nearby_place_id' => $place->id, 'name' => $place->name])
                ->log('Nearby place added');

            return true;
        });
    }
}
