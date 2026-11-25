<?php

namespace App\Data\Reports;

use App\Enums\ReportGroup;
use Spatie\LaravelData\Data;

/**
 * The chart above the Occupancy and History & forecast tables: one point per day, or per month for a range longer than
 * `RoomStatsChart::DAILY_UP_TO_DAYS`. A month's figures are the report's own totals over its days, so they match the
 * total row of a table filtered to that month. See docs/22-reports/06-report-charts.md.
 */
class RoomStatsChartData extends Data
{
    /**
     * @param  list<RoomStatsChartPointData>  $points
     */
    public function __construct(
        public ReportGroup $step,
        public array $points,
    ) {}
}
