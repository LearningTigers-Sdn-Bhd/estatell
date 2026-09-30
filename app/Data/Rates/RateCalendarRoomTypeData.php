<?php

namespace App\Data\Rates;

use App\Models\RoomType;
use Spatie\LaravelData\Data;

/** One room type group in the rate calendar, with a row for each plan that sells it. */
class RateCalendarRoomTypeData extends Data
{
    /**
     * @param  list<RateCalendarPlanData>  $plans
     * @param  list<RateCalendarAvailabilityData>  $availability  One entry for each date of the range.
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $baseOccupancy,
        public int $maxAdults,
        public array $plans,
        public array $availability,
    ) {}

    /**
     * @param  list<RateCalendarPlanData>  $plans
     * @param  list<RateCalendarAvailabilityData>  $availability
     */
    public static function fromModel(RoomType $roomType, array $plans, array $availability): self
    {
        return new self(
            id: $roomType->id,
            name: $roomType->name,
            baseOccupancy: $roomType->base_occupancy,
            maxAdults: $roomType->max_adults,
            plans: $plans,
            availability: $availability,
        );
    }
}
