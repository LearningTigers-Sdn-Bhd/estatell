<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportBreakdown;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportRow;

/**
 * A report with a small summary table under its main table (Payments: the total for each method or the cash-up for each staff member). The screen and the
 * PDF show it; the spreadsheet does not.
 */
interface HasBreakdown
{
    /**
     * The summary of the rows it is given: every row that matches the filters on the screen, only the selected rows in
     * an export. The filters carry the `group` that picks the summary.
     *
     * @param  list<ReportRow>  $rows
     */
    public function breakdown(array $rows, ReportFilters $filters): ReportBreakdown;
}
