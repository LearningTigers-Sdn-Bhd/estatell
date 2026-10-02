<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One priced night of a quote. All amounts are decimal strings. `amount` is what the guest pays for the night.
 */
class BookingQuoteNightData extends Data
{
    public function __construct(
        public string $date,
        public string $base,
        public string $extra,
        public string $supplement,
        public string $discount,
        public string $amount,
    ) {}
}
