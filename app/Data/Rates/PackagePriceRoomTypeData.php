<?php

namespace App\Data\Rates;

use App\Models\RoomType;
use Spatie\LaravelData\Data;

/** A room type a package plan sells, as a group of the price grid. */
class PackagePriceRoomTypeData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $sellUnitLabel,
    ) {}

    public static function fromModel(RoomType $roomType): self
    {
        return new self(
            id: $roomType->id,
            name: $roomType->name,
            sellUnitLabel: $roomType->sell_unit->label(),
        );
    }
}
