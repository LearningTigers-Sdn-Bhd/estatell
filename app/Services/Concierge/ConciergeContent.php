<?php

namespace App\Services\Concierge;

use App\Data\Concierge\ConciergeAmenityData;
use App\Data\Concierge\ConciergePlaceData;
use App\Models\NearbyPlace;
use App\Models\Property;
use App\Services\BookingEngine\NearbyPlaceSuggestions;
use Illuminate\Support\Collection;

/**
 * What the property tells a guest about itself: the amenities it ticked and the places it listed nearby, in the order
 * the owner set. The same registries the booking engine shows. It reads only.
 */
class ConciergeContent
{
    public function __construct(private readonly NearbyPlaceSuggestions $nearby) {}

    /**
     * @return Collection<int, ConciergeAmenityData>
     */
    public function amenities(Property $property): Collection
    {
        return $property->amenities()->with('category')->orderBy('position')->get()
            ->map(fn ($amenity): ConciergeAmenityData => new ConciergeAmenityData(
                $amenity->name,
                $amenity->category->name,
                $amenity->pivot->location,
                $amenity->pivot->opening_hours,
                $amenity->pivot->fee_information,
                (bool) $amenity->pivot->reservation_required,
                $amenity->pivot->reservation_instructions,
                $amenity->pivot->guest_notes,
            ));
    }

    /**
     * @return Collection<int, ConciergePlaceData>
     */
    public function places(Property $property): Collection
    {
        return $this->nearby->ownPlaces($property)->filter(fn (NearbyPlace $place): bool => (bool) $place->pivot->active)->values()
            ->map(fn (NearbyPlace $place): ConciergePlaceData => new ConciergePlaceData(
                $place->pivot->display_name ?? $place->name,
                $place->pivot->description,
                $place->google_maps_url,
                $place->getAttribute('distance_km') === null ? null : __(':km km', ['km' => number_format((float) $place->getAttribute('distance_km'), 1)]),
            ));
    }
}
