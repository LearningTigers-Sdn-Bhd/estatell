<?php

namespace App\Data\Reports;

use App\Enums\ReportGroup;
use Spatie\LaravelData\Data;

/**
 * The charts above the Revenue by transaction code table: the codes ranked by amount, and the total of each day or
 * month (the report's Group by). Both add up to the table's total. See docs/22-reports/06-report-charts.md.
 */
class RevenueChartData extends Data
{
    /**
     * @param  list<RevenueChartCodeData>  $codes
     * @param  list<RevenueChartPeriodData>  $periods
     */
    public function __construct(
        public ReportGroup $group,
        public array $codes,
        public array $periods,
    ) {}
}
