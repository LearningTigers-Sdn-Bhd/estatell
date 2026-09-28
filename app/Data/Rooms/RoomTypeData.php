<?php

namespace App\Data\Rooms;

use App\Enums\SellUnit;
use App\Models\RoomType;
use Spatie\LaravelData\Data;

/**
 * One row of the room types list, and the values of its edit form.
 */
class RoomTypeData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public SellUnit $sellUnit,
        public int $maxAdults,
        public int $maxChildren,
        public int $baseOccupancy,
        public bool $smokingAllowed,
        public bool $petsAllowed,
        public bool $archived,
    ) {}

    public static function fromModel(RoomType $roomType): self
    {
        return new self(
            id: $roomType->id,
            name: $roomType->name,
            description: $roomType->description,
            sellUnit: $roomType->sell_unit,
            maxAdults: $roomType->max_adults,
            maxChildren: $roomType->max_children,
            baseOccupancy: $roomType->base_occupancy,
            smokingAllowed: $roomType->smoking_allowed,
            petsAllowed: $roomType->pets_allowed,
            archived: $roomType->archived_at !== null,
        );
    }
}
