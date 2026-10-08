<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Models\Property;

/**
 * A report with a short note under its title, on the screen and in the PDF.
 */
interface HasNote
{
    /**
     * The note for the filters, or null when there is none.
     */
    public function note(Property $property, ReportFilters $filters): ?string;
}
