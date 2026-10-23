<?php

namespace App\Data\StayView;

use Spatie\LaravelData\Data;

/**
 * One rate plan that sells one room type, with its nightly price for each date that has one. The rows come in the
 * order of the room types and then the rate plans, so the first row of a room type is its first plan.
 */
class RatePriceRowData extends Data
{
    /**
     * @param  list<DateRateData>  $days
     */
    public function __construct(
        public int $roomTypeId,
        public string $roomTypeName,
        public int $ratePlanId,
        public string $ratePlanName,
        public array $days,
    ) {}
}
