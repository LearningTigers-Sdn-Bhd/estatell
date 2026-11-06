<?php

namespace App\Data\BookingEngine;

use App\Models\NearbyPlace;
use Spatie\LaravelData\Data;

/**
 * An Approved place suggested to a property, with its straight-line distance such as "3.8 km".
 */
class NearbyPlaceSuggestionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $googleMapsUrl,
        public string $distance,
        public float $distanceKm,
    ) {}

    public static function fromModel(NearbyPlace $place): self
    {
        $distanceKm = (float) $place->getAttribute('distance_km');

        return new self(
            id: $place->id,
            name: $place->name,
            googleMapsUrl: $place->google_maps_url,
            distance: __(':km km', ['km' => number_format($distanceKm, 1)]),
            distanceKm: $distanceKm,
        );
    }
}
