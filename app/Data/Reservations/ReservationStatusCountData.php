<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One status tab with its count. `value` is empty for the All tab.
 */
class ReservationStatusCountData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public int $count,
    ) {}
}
