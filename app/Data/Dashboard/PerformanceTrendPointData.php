<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * One step of the trend chart with the same step of the compared range. A step without figures is null.
 */
class PerformanceTrendPointData extends Data
{
    /**
     * @param  string  $date  `Y-m-d` for a day, `Y-m` for a month.
     * @param  string|null  $previousDate  Null when the compared range has no such step.
     */
    public function __construct(
        public string $date,
        public ?float $occupancy,
        public ?float $adr,
        public ?float $revpar,
        public ?string $previousDate,
        public ?float $previousOccupancy,
        public ?float $previousAdr,
        public ?float $previousRevpar,
    ) {}
}
