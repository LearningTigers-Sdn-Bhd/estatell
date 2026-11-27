<?php

namespace App\Data\Reports;

use Spatie\LaravelData\Data;

/**
 * One closed date of the Overview "last 10 days" table, from the night audit snapshot: rooms and beds sold and the
 * occupancy. A date with no snapshot (before go-live) is left out.
 */
class RoomsOnBooksRecentDayData extends Data
{
    public function __construct(
        public string $date,
        public int $sold,
        public string $occupancy,
    ) {}
}
