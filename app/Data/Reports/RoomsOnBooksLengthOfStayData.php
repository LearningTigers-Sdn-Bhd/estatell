<?php

namespace App\Data\Reports;

use Spatie\LaravelData\Data;

/**
 * One band of the Overview pick-up trend: the new bookings whose stay is that many nights, with their room nights,
 * average rate and booked revenue.
 */
class RoomsOnBooksLengthOfStayData extends Data
{
    public function __construct(
        public string $label,
        public int $rooms,
        public int $nights,
        public string $rate,
        public string $revenue,
    ) {}
}
