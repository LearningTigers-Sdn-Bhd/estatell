<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * One bar of the Tonight tile.
 */
class TonightNightData extends Data
{
    /**
     * @param  string  $date  `Y-m-d`.
     * @param  int  $occupancy  Percent, 0 to 100.
     */
    public function __construct(
        public string $date,
        public int $occupancy,
        public int $sold,
        public int $total,
    ) {}
}
