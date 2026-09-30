<?php

namespace App\Services\Rates\Pricing;

use App\Models\RatePlan;
use App\Models\RoomType;

/**
 * What a strategy needs to price the nights of one stay. Child ages are already mapped to age groups.
 */
class StayContext
{
    /**
     * @param  list<int>  $childAgeGroupIds  One age group id per child
     */
    public function __construct(
        public readonly RatePlan $ratePlan,
        public readonly RoomType $roomType,
        public readonly int $adults,
        public readonly array $childAgeGroupIds,
        public readonly int $beds,
        public readonly string $currency,
    ) {}
}
