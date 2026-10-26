<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Models\Property;

/**
 * The Checked out report: the units that checked out in the range, with their balance. A stub with no rows until the
 * report is built. See docs/22-reports/02-guest-list-and-daily-report.md#2-checked-out.
 */
class CheckedOutReport implements Report
{
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        return [];
    }
}
