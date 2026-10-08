<?php

namespace App\Data\Reports;

use Spatie\LaravelData\Data;

/**
 * A small summary table under a report: a title, the headings and rows of display text, and a total row. The first
 * column is text and every other column is a right-aligned figure. The screen and the PDF print it as given.
 * `subtotalRows` lists the indexes in `rows` that are subtotals, which the screen and the PDF show in bold.
 */
class ReportBreakdown extends Data
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string>>  $rows
     * @param  list<string>  $total
     * @param  list<int>  $subtotalRows
     */
    public function __construct(
        public string $title,
        public array $headings,
        public array $rows,
        public array $total,
        public array $subtotalRows = [],
    ) {}
}
