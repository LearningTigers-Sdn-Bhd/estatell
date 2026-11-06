<?php

namespace App\Data\BookingEngine;

use App\Models\NearbyPlace;
use Spatie\LaravelData\Data;

/**
 * One nearby place on a property's Nearby places tab. The status is left out, because owners never see it.
 */
class PropertyNearbyPlaceData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $sharedName,
        public ?string $displayName,
        public ?string $description,
        public string $googleMapsUrl,
        public bool $active,
        public ?string $distance,
        public ?float $distanceKm,
    ) {}

    public static function fromModel(NearbyPlace $place): self
    {
        $distanceKm = $place->getAttribute('distance_km');

        return new self(
            id: $place->id,
            name: $place->pivot->display_name ?? $place->name,
            sharedName: $place->name,
            displayName: $place->pivot?->display_name,
            description: $place->pivot?->description,
            googleMapsUrl: $place->google_maps_url,
            active: (bool) $place->pivot?->active,
            distance: $distanceKm === null ? null : __(':km km', ['km' => number_format((float) $distanceKm, 1)]),
            distanceKm: $distanceKm === null ? null : (float) $distanceKm,
        );
    }
}
