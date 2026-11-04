<?php

namespace App\Services\BookingEngine;

use App\Models\NearbyPlace;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * Approved places near a property that it does not have yet, nearest first, and the distance to the places it has.
 * Distance is a straight line in km, because travel times would need a paid Google API.
 */
class NearbyPlaceSuggestions
{
    public const int RADIUS_KM = 25;

    public const int LIMIT = 10;

    private const string DISTANCE_SQL = '6371 * acos(least(1, greatest(-1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))))';

    /**
     * @return Collection<int, NearbyPlace> each with a `distance_km` attribute
     */
    public function for(Property $property): Collection
    {
        if ($property->latitude === null || $property->longitude === null) {
            return collect();
        }

        $bindings = [$property->latitude, $property->longitude, $property->latitude];

        return NearbyPlace::query()
            ->approved()
            ->whereNotIn('id', $property->nearbyPlaces()->select('nearby_places.id'))
            ->selectRaw('nearby_places.*, '.self::DISTANCE_SQL.' as distance_km', $bindings)
            ->whereRaw(self::DISTANCE_SQL.' <= ?', [...$bindings, self::RADIUS_KM])
            ->orderBy('distance_km')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * The places the property has, in its order. Each has a `distance_km` attribute when the property has a location.
     *
     * @return Collection<int, NearbyPlace>
     */
    public function ownPlaces(Property $property): Collection
    {
        if ($property->latitude === null || $property->longitude === null) {
            return $property->nearbyPlaces()->get();
        }

        return $property->nearbyPlaces()
            ->select('nearby_places.*')
            ->selectRaw(self::DISTANCE_SQL.' as distance_km', [$property->latitude, $property->longitude, $property->latitude])
            ->get();
    }
}
