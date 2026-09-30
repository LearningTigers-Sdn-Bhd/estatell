<?php

namespace App\Data\Rates;

use App\Enums\SellUnit;
use App\Models\RatePlan;
use App\Models\RoomType;
use Spatie\LaravelData\Data;

/** One checkbox of the plan editor's Room types tab. */
class RatePlanRoomTypeChoiceData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public SellUnit $sellUnit,
        public bool $fits,
    ) {}

    public static function forPlan(RoomType $roomType, RatePlan $ratePlan): self
    {
        return new self(
            id: $roomType->id,
            name: $roomType->name,
            sellUnit: $roomType->sell_unit,
            fits: $ratePlan->pricing_basis->fits($roomType->sell_unit),
        );
    }
}
