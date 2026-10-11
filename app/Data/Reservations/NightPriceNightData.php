<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One night of a unit in the Change price Dialog: its price, and whether its room line is already posted.
 */
class NightPriceNightData extends Data
{
    public function __construct(
        public int $id,
        public string $date,
        public string $amount,
        public bool $charged,
    ) {}
}
