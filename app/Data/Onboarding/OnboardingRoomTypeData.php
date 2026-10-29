<?php

namespace App\Data\Onboarding;

use App\Enums\SellUnit;
use App\Models\RoomType;
use Spatie\LaravelData\Data;

/**
 * One saved room type as a row of the onboarding Room types table. `hasRooms` counts archived rooms too, because Sold
 * by stays fixed once the type has any room. Load the model with `withExists('rooms')`.
 */
class OnboardingRoomTypeData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public SellUnit $sellUnit,
        public int $maxAdults,
        public int $maxChildren,
        public bool $hasRooms,
    ) {}

    public static function fromModel(RoomType $roomType): self
    {
        return new self(
            id: $roomType->id,
            name: $roomType->name,
            sellUnit: $roomType->sell_unit,
            maxAdults: $roomType->max_adults,
            maxChildren: $roomType->max_children,
            hasRooms: $roomType->rooms_exists,
        );
    }
}
