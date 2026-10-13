<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportRow;
use App\Data\Reports\ReportSection;
use Spatie\LaravelData\Data;

/**
 * A report whose rows fall into sections, such as one boat departure each. The screen shows a heading and a subtotal
 * for each section, and the PDF prints each section on its own table. The exports stay flat.
 */
interface HasSections
{
    /**
     * The sections of the rows it is given, in the order they print. Each row given sits in exactly one section.
     *
     * @param  list<Data&ReportRow>  $rows
     * @return list<ReportSection>
     */
    public function sections(array $rows, ReportFilters $filters): array;
}
