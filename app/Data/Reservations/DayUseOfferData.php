<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One day use block a plan sells on a date: the number of hours, the price, and when it ends (`H:i`).
 */
class DayUseOfferData extends Data
{
    public function __construct(
        public int $hours,
        public string $amount,
        public string $endsAt,
    ) {}
}
