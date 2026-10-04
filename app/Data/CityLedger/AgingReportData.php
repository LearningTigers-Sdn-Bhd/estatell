<?php

namespace App\Data\CityLedger;

use Spatie\LaravelData\Data;

/**
 * The aging report as at a date: one row for each company with a balance, and the totals.
 */
class AgingReportData extends Data
{
    /**
     * @param  list<AgingRowData>  $rows
     */
    public function __construct(
        public string $asAt,
        public array $rows,
        public AgingRowData $totals,
    ) {}
}
