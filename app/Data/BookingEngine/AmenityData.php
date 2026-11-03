<?php

namespace App\Data\BookingEngine;

use App\Enums\AmenityType;
use App\Models\Amenity;
use Spatie\LaravelData\Data;

/**
 * One amenity on the shared list. The admin page shows all of them; the pickers show the ones a property or room type can tick.
 */
class AmenityData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public AmenityType $type,
        public string $typeLabel,
        public string $category,
        public bool $active,
    ) {}

    public static function fromModel(Amenity $amenity): self
    {
        return new self(
            id: $amenity->id,
            name: $amenity->name,
            type: $amenity->type,
            typeLabel: $amenity->type->label(),
            category: $amenity->category,
            active: $amenity->active,
        );
    }
}
