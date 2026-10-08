<?php

namespace App\Data\Reports;

use App\Enums\ColumnAlign;
use Spatie\LaravelData\Data;

/**
 * One column of a resolved layout: the column of the report plus whether it shows and how it aligns. `align` is the
 * alignment that applies (a stored override or the code default); `defaultAlign` is the code default.
 */
class ReportLayoutColumnData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public ColumnAlign $align,
        public bool $visible,
        public ColumnAlign $defaultAlign,
    ) {}

    public static function fromColumn(ReportColumn $column, bool $visible, ?ColumnAlign $align = null): self
    {
        return new self($column->key, $column->label, $align ?? $column->align, $visible, $column->align);
    }
}
