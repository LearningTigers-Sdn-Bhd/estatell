<?php

namespace App\Data\Reports;

use App\Enums\ColumnAlign;
use Spatie\LaravelData\Data;

/**
 * One column of a resolved layout: the column of the report plus whether it shows.
 */
class ReportLayoutColumnData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public ColumnAlign $align,
        public bool $visible,
    ) {}

    public static function fromColumn(ReportColumn $column, bool $visible): self
    {
        return new self($column->key, $column->label, $column->align, $visible);
    }
}
