<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * Days from booking to arrival for the live units arriving in the range, walk-ins left out.
 */
class LeadTimeData extends Data
{
    /**
     * @param  PerformanceKpiData  $average  Whole days. Null value when no unit is counted.
     * @param  int  $units  Units counted.
     * @param  list<LeadTimeBucketData>  $buckets  Always the four buckets, in order.
     */
    public function __construct(
        public PerformanceKpiData $average,
        public int $units,
        public array $buckets,
    ) {}
}
