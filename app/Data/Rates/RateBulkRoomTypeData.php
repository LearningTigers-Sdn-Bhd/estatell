<?php

namespace App\Data\Rates;

use App\Models\RoomType;
use Spatie\LaravelData\Data;

/** One room type a plan sells, in the bulk edit pickers and table: its adult limits for prices by adults. */
class RateBulkRoomTypeData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $baseOccupancy,
        public int $maxAdults,
    ) {}

    public static function fromModel(RoomType $roomType): self
    {
        return new self(
            id: $roomType->id,
            name: $roomType->name,
            baseOccupancy: $roomType->base_occupancy,
            maxAdults: $roomType->max_adults,
        );
    }
}
