<?php

namespace App\Data\Reports;

use App\Enums\ColumnAlign;
use Spatie\LaravelData\Data;

/**
 * One column of a report. The `key` is stable: stored layouts refer to it, so renaming a key is a migration. A column
 * with a `feature` (a Pennant feature class) is left out of the layout while the feature is off for the property.
 */
class ReportColumn extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public ColumnAlign $align,
        public bool $defaultVisible,
        public ?string $feature = null,
    ) {}
}
