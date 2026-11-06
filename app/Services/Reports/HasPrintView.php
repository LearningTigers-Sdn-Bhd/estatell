<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportRow;
use App\Models\Property;

/**
 * A report that prints its own PDF layout instead of the shared table, such as the House status grid. The screen and
 * the spreadsheet still use the rows and columns.
 */
interface HasPrintView
{
    /**
     * @return view-string
     */
    public function printView(): string;

    /**
     * The print view is a fixed design, so it sets its own page, whatever the saved report layout says.
     *
     * @return 'portrait'|'landscape'
     */
    public function printOrientation(): string;

    /**
     * The data of the print view, added to the shared title, dates and print profile.
     *
     * @param  list<ReportRow>  $rows
     * @return array<string, mixed>
     */
    public function printData(Property $property, ReportFilters $filters, array $rows): array;
}
