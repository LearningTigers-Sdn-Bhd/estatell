<?php

namespace App\Data\Reports;

use App\Enums\ColumnAlign;
use Spatie\LaravelData\Data;

/**
 * One column of a report. The `key` is stable: stored layouts refer to it, so renaming a key is a migration.
 */
class ReportColumn extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public ColumnAlign $align,
        public bool $defaultVisible,
    ) {}
}
