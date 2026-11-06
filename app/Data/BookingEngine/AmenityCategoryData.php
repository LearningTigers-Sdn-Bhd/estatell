<?php

namespace App\Data\BookingEngine;

use App\Enums\AmenityType;
use App\Models\AmenityCategory;
use Spatie\LaravelData\Data;

/**
 * One row of the admin Categories tab. Needs `amenities_count` loaded.
 */
class AmenityCategoryData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public AmenityType $type,
        public int $amenitiesCount,
    ) {}

    public static function fromModel(AmenityCategory $category): self
    {
        return new self(
            id: $category->id,
            name: $category->name,
            type: $category->type,
            amenitiesCount: (int) $category->getAttribute('amenities_count'),
        );
    }
}
