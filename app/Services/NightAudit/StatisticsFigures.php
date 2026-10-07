<?php

namespace App\Services\NightAudit;

use Brick\Money\Money;

/**
 * The statistics of one business date, as `StatisticsCalculator` worked them out. `occupancy` is a percent with two
 * decimals.
 */
class StatisticsFigures
{
    public function __construct(
        public readonly int $roomsAvailable,
        public readonly int $roomsSold,
        public readonly int $bedsAvailable,
        public readonly int $bedsSold,
        public readonly string $occupancy,
        public readonly Money $adr,
        public readonly Money $revpar,
        public readonly int $arrivals,
        public readonly int $departures,
        public readonly int $noShows,
        public readonly int $inHouseGuests,
    ) {}
}
