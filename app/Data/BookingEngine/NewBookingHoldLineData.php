<?php

namespace App\Data\BookingEngine;

use Spatie\LaravelData\Data;

/**
 * One room or bed a guest chose. A bed is for one adult, so `CreateBookingHold` ignores the party of a bed line.
 */
class NewBookingHoldLineData extends Data
{
    /**
     * @param  list<int>  $childAges
     */
    public function __construct(
        public int $roomTypeId,
        public int $ratePlanId,
        public int $adults,
        public array $childAges = [],
    ) {}
}
