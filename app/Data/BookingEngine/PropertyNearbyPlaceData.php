<?php

namespace App\Data\BookingEngine;

use App\Models\NearbyPlace;
use Spatie\LaravelData\Data;

/**
 * One nearby place on a property's Content tab. The status is left out, because owners never see it.
 */
class PropertyNearbyPlaceData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public string $googleMapsUrl,
    ) {}

    public static function fromModel(NearbyPlace $place): self
    {
        return new self(
            id: $place->id,
            name: $place->name,
            description: $place->pivot?->description,
            googleMapsUrl: $place->google_maps_url,
        );
    }
}
