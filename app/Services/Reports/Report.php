<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Models\Property;
use Spatie\LaravelData\Data;

/**
 * One report. The screen, the PDF and the spreadsheet all call `rows()`, so they always match.
 */
interface Report
{
    /**
     * The rows for the property and the filters, each with a stable `key` (a `key` property).
     * With `$rowKeys`, only the rows with those keys are returned.
     *
     * @param  list<int|string>|null  $rowKeys
     * @return list<Data>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array;
}
