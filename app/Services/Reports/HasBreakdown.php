<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportBreakdown;
use App\Data\Reports\ReportRow;
use Spatie\LaravelData\Data;

/**
 * A report with a small summary table under its main table (Payments: the total for each method). The screen and the
 * PDF show it; the spreadsheet does not.
 */
interface HasBreakdown
{
    /**
     * The summary of the rows it is given: every row that matches the filters on the screen, only the selected rows in
     * an export.
     *
     * @param  list<Data&ReportRow>  $rows
     */
    public function breakdown(array $rows): ReportBreakdown;
}
