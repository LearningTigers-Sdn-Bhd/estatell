<?php

namespace App\Data\Reports;

use Spatie\LaravelData\Data;

/**
 * One window of the Overview pace table: the room nights on the books, the rooms per day (RPD, nights over the days
 * of the window) and the occupancy of the rooms and beds on sale.
 */
class RoomsOnBooksPaceData extends Data
{
    public function __construct(
        public string $label,
        public string $from,
        public string $to,
        public int $nights,
        public string $rpd,
        public string $occupancy,
    ) {}
}
