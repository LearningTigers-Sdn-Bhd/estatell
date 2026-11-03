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
        public string $distance,
    ) {}

    public static function fromModel(NearbyPlace $place): self
    {
        return new self(
            id: $place->id,
            name: $place->name,
            distance: __(':km km', ['km' => number_format((float) $place->getAttribute('distance_km'), 1)]),
        );
    }
}
