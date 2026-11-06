<?php

namespace App\Actions\BookingEngine;

use App\Enums\NearbyPlaceStatus;
use App\Models\NearbyPlace;
use App\Models\Property;
use App\Models\User;
use App\Services\BookingEngine\MapsPlace;
use Illuminate\Support\Facades\DB;

class AddNearbyPlaceFromLink
{
    /**
     * Link the place read from a Google Maps link to the property. A place already in the registry is reused; a new one
     * is added as Pending. Returns null, and writes nothing, when the property already has the place or admins rejected it.
     */
    public function __invoke(User $actor, Property $property, MapsPlace $mapsPlace): ?NearbyPlace
    {
        return DB::transaction(function () use ($actor, $property, $mapsPlace): ?NearbyPlace {
            $place = NearbyPlace::query()->firstOrCreate(
                ['fingerprint' => $mapsPlace->fingerprint],
                [
                    'name' => $mapsPlace->name,
                    'latitude' => $mapsPlace->latitude,
                    'longitude' => $mapsPlace->longitude,
                    'google_maps_url' => $mapsPlace->url,
                    'status' => NearbyPlaceStatus::Pending,
                    'created_by_property_id' => $property->id,
                ],
            );

            if ($place->status === NearbyPlaceStatus::Rejected || $property->nearbyPlaces()->whereKey($place->id)->exists()) {
                return null;
            }

            $position = (int) $property->nearbyPlaces()->max('property_nearby_places.position') + 1;
            $property->nearbyPlaces()->attach($place->id, ['position' => $position]);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('property.nearby_place_added')
                ->withProperties(['nearby_place_id' => $place->id, 'name' => $place->name])
                ->log('Nearby place added');

            return $place;
        });
    }
}
