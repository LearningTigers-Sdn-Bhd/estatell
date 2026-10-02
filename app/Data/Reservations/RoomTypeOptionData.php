<?php

namespace App\Data\Reservations;

use App\Enums\SellUnit;
use App\Models\RoomType;
use Spatie\LaravelData\Data;

/**
 * A room type to pick in the Add unit Sheet.
 */
class RoomTypeOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $isBed,
        public int $maxAdults,
        public int $maxChildren,
    ) {}

    public static function fromModel(RoomType $roomType): self
    {
        return new self($roomType->id, $roomType->name, $roomType->sell_unit === SellUnit::Bed, $roomType->max_adults, $roomType->max_children);
    }
}
