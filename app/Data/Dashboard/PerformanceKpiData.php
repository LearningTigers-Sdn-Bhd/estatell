<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * One KPI tile: the figure, the figure of the compared range and the change. All decimal strings, or null.
 */
class PerformanceKpiData extends Data
{
    /**
     * @param  string|null  $value  Null when the range has no figure to work it out from.
     * @param  string|null  $previous  Null when the compared range has none.
     * @param  string|null  $change  Worked out from the unrounded figures. Points for occupancy, percent for ADR and RevPAR, days for lead time. Null hides the comparison.
     */
    public function __construct(
        public ?string $value,
        public ?string $previous,
        public ?string $change,
    ) {}
}
