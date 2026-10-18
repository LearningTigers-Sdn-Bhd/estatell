<?php

namespace App\Data\Portal;

use App\Enums\SellUnit;
use App\Models\RoomType;
use Spatie\LaravelData\Data;

/**
 * A room type the company can book: at least one of its rate plans is visible to the company.
 */
class PortalRoomTypeData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public SellUnit $sellUnit,
        public int $maxAdults,
        public int $maxChildren,
    ) {}

    public static function fromModel(RoomType $roomType): self
    {
        return new self($roomType->id, $roomType->name, $roomType->description, $roomType->sell_unit, $roomType->max_adults, $roomType->max_children);
    }
}
