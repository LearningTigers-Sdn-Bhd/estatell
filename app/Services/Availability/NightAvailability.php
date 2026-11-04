<?php

namespace App\Services\Availability;

use Carbon\CarbonImmutable;

/**
 * The units of one room type for one night: how many there are, how many are blocked, booked or held for an online
 * checkout, and what is left.
 * `available` never goes below zero. A `booked` count above the free units is an overbooking.
 */
class NightAvailability
{
    public readonly int $available;

    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly int $total,
        public readonly int $blocked,
        public readonly int $booked,
        public readonly int $held = 0,
    ) {
        $this->available = max(0, $total - $blocked - $booked - $held);
    }
}
