<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportRow;
use App\Data\Reports\ReportTotals;

/**
 * A report with a total row under its table. The screen, the PDF and the spreadsheet all ask for it.
 */
interface HasTotals
{
    /**
     * The sums of the rows it is given: every row that matches the filters on the screen, only the selected rows in an
     * export. Ratios are worked out again from the sums, never averaged.
     *
     * @param  list<ReportRow>  $rows
     */
    public function totals(array $rows): ReportTotals;
}
