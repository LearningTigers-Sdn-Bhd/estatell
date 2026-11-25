<?php

namespace App\Data\Reports;

use App\Enums\ForecastBasis;
use Spatie\LaravelData\Data;

/**
 * One bar of a room statistics chart: a day (`Y-m-d`) or a month (`Y-m`) with its figures as two-decimal strings.
 * `basis` is Actual for a closed day, Today for the open day (or a month that holds it) and Forecast for a future one.
 */
class RoomStatsChartPointData extends Data
{
    public function __construct(
        public string $date,
        public ForecastBasis $basis,
        public string $occupancy,
        public string $adr,
        public string $revpar,
        public string $revenue,
    ) {}
}
