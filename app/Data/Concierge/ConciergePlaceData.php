<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * A place near the property, under the name and note the property gave it.
 */
class ConciergePlaceData extends Data
{
    public function __construct(
        public string $name,
        public ?string $description,
        public string $mapsUrl,
    ) {}
}
