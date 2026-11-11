<?php

namespace App\Services\Concierge;

use App\Data\Concierge\ConciergeAmenityData;
use App\Data\Concierge\ConciergePlaceData;
use App\Models\NearbyPlace;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * What the property tells a guest about itself: the amenities it ticked and the places it listed nearby, in the order
 * the owner set. The same registries the booking engine shows. It reads only.
 */
class ConciergeContent
{
    /**
     * @return Collection<int, ConciergeAmenityData>
     */
    public function amenities(Property $property): Collection
    {
        return $property->amenities()->with('category')->orderBy('position')->get()
            ->map(fn ($amenity): ConciergeAmenityData => new ConciergeAmenityData($amenity->name, $amenity->category->name));
    }

    /**
     * @return Collection<int, ConciergePlaceData>
     */
    public function places(Property $property): Collection
    {
        return $property->nearbyPlaces()->wherePivot('active', true)->orderByPivot('position')->get()
            ->map(fn (NearbyPlace $place): ConciergePlaceData => new ConciergePlaceData(
                $place->pivot->display_name ?? $place->name,
                $place->pivot->description,
                $place->google_maps_url,
            ));
    }
}
