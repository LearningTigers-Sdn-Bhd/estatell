<?php

namespace App\Data\Reports;

use Spatie\LaravelData\Data;

/**
 * A small summary table under a report: a title, the headings and rows of display text, and a total row. The first
 * column is text and every other column is a right-aligned figure. The screen and the PDF print it as given.
 */
class ReportBreakdown extends Data
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string>>  $rows
     * @param  list<string>  $total
     */
    public function __construct(
        public string $title,
        public array $headings,
        public array $rows,
        public array $total,
    ) {}
}
