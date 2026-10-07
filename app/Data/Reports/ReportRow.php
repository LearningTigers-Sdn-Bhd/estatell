<?php

namespace App\Data\Reports;

/**
 * A row of a report that can print as text or go into a spreadsheet. The PDF reads `cells()` and the spreadsheet reads
 * `values()`, both by column key.
 */
interface ReportRow
{
    /**
     * The display text of each column, keyed by column key. Money has two decimals; the currency is in the heading.
     *
     * @return array<string, string>
     */
    public function cells(): array;

    /**
     * The typed value of each column for spreadsheets, keyed by column key: money and counts as numbers, dates as
     * `Y-m-d`, enums as their label and nulls as an empty string.
     *
     * @return array<string, int|float|string>
     */
    public function values(): array;
}
