<?php

namespace App\Data\Rates;

use App\Models\RoomType;
use Spatie\LaravelData\Data;

/** One room type group in the rate calendar, with a row for each plan that sells it. */
class RateCalendarRoomTypeData extends Data
{
    /**
     * @param  list<RateCalendarPlanData>  $plans
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $baseOccupancy,
        public int $maxAdults,
        public array $plans,
    ) {}

    /**
     * @param  list<RateCalendarPlanData>  $plans
     */
    public static function fromModel(RoomType $roomType, array $plans): self
    {
        return new self(
            id: $roomType->id,
            name: $roomType->name,
            baseOccupancy: $roomType->base_occupancy,
            maxAdults: $roomType->max_adults,
            plans: $plans,
        );
    }
}
