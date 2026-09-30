<?php

namespace App\Data\Rates;

use App\Models\RoomType;
use Spatie\LaravelData\Data;

/** One room type on the plan editor's Pricing tab: its adult limits and how far its prices run. */
class RatePricingRoomTypeData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $baseOccupancy,
        public int $maxAdults,
        public ?string $pricedUntil,
        public bool $ending,
    ) {}

    public static function fromModel(RoomType $roomType, ?string $pricedUntil, bool $ending): self
    {
        return new self(
            id: $roomType->id,
            name: $roomType->name,
            baseOccupancy: $roomType->base_occupancy,
            maxAdults: $roomType->max_adults,
            pricedUntil: $pricedUntil,
            ending: $ending,
        );
    }
}
