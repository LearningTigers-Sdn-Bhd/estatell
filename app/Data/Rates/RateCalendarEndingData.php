<?php

namespace App\Data\Rates;

use App\Models\RatePlan;
use Spatie\LaravelData\Data;

/** A plan with its own prices whose room types have no price for the next 60 days. */
class RateCalendarEndingData extends Data
{
    /**
     * @param  list<string>  $roomTypeNames
     */
    public function __construct(
        public int $ratePlanId,
        public string $ratePlanName,
        public array $roomTypeNames,
    ) {}

    /**
     * @param  list<string>  $roomTypeNames
     */
    public static function fromModel(RatePlan $ratePlan, array $roomTypeNames): self
    {
        return new self(ratePlanId: $ratePlan->id, ratePlanName: $ratePlan->name, roomTypeNames: $roomTypeNames);
    }
}
