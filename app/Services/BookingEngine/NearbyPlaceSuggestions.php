<?php

namespace App\Services\BookingEngine;

use App\Models\NearbyPlace;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * Approved places near a property that it does not have yet, nearest first. Distance is a straight line in km, because
 * travel times would need a paid Google API.
 */
class NearbyPlaceSuggestions
{
    public const int RADIUS_KM = 25;

    public const int LIMIT = 10;

    /**
     * @return Collection<int, NearbyPlace> each with a `distance_km` attribute
     */
    public function for(Property $property): Collection
    {
        if ($property->latitude === null || $property->longitude === null) {
            return collect();
        }

        $distance = '6371 * acos(least(1, greatest(-1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))))';
        $bindings = [$property->latitude, $property->longitude, $property->latitude];

        return NearbyPlace::query()
            ->approved()
            ->whereNotIn('id', $property->nearbyPlaces()->select('nearby_places.id'))
            ->selectRaw("nearby_places.*, {$distance} as distance_km", $bindings)
            ->whereRaw("{$distance} <= ?", [...$bindings, self::RADIUS_KM])
            ->orderBy('distance_km')
            ->limit(self::LIMIT)
            ->get();
    }
}
