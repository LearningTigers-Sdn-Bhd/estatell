<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * The Arrivals or Departures tile: the units still to act on, and all units of the business date.
 */
class UnitCountTileData extends Data
{
    /**
     * @param  int  $remaining  Units still Confirmed (arrivals) or still CheckedIn (departures).
     * @param  int  $total  Units that arrive or depart on the date, as the report counts them.
     */
    public function __construct(
        public int $remaining,
        public int $total,
    ) {}
}
