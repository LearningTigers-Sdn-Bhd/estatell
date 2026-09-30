<?php

namespace App\Services\Rates;

use App\Models\RatePlan;
use App\Models\RoomType;
use Carbon\CarbonInterface;

/**
 * What to price: one plan and room type, the dates and the party. Departure is the check-out date.
 */
class RateQuoteRequest
{
    /**
     * @param  list<int>  $childAges
     */
    public function __construct(
        public readonly RatePlan $ratePlan,
        public readonly RoomType $roomType,
        public readonly CarbonInterface $arrival,
        public readonly CarbonInterface $departure,
        public readonly int $adults,
        public readonly array $childAges = [],
        public readonly int $beds = 1,
    ) {}
}
