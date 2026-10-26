<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Models\Property;

/**
 * Daily report › Revenue: one row for each date or month, with revenue buckets and one column for each tax. A stub
 * with no rows until the report is built. See docs/22-reports/02-guest-list-and-daily-report.md#revenue.
 */
class DailyRevenueReport implements Report
{
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        return [];
    }
}
