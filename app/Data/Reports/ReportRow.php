<?php

namespace App\Data\Reports;

/**
 * A row of a report that can print as text. The PDF and the spreadsheet read the cells by column key.
 */
interface ReportRow
{
    /**
     * The display text of each column, keyed by column key. Money has two decimals; the currency is in the heading.
     *
     * @return array<string, string>
     */
    public function cells(): array;
}
