<?php

namespace App\Data\Reports;

use App\Enums\PageOrientation;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * The resolved layout of a report: the orientation and every column of the report in order, each with its visibility.
 * The screen, the PDF and the exports all read it.
 */
class ReportLayoutData extends Data
{
    /**
     * @param  list<ReportLayoutColumnData>  $columns
     */
    public function __construct(
        public PageOrientation $orientation,
        #[DataCollectionOf(ReportLayoutColumnData::class)]
        public array $columns,
    ) {}

    /**
     * The columns that show, in order.
     *
     * @return list<ReportLayoutColumnData>
     */
    public function visibleColumns(): array
    {
        return array_values(array_filter($this->columns, fn (ReportLayoutColumnData $column): bool => $column->visible));
    }

    /**
     * The keys of the columns that show, in order.
     *
     * @return list<string>
     */
    public function visibleKeys(): array
    {
        return array_map(fn (ReportLayoutColumnData $column): string => $column->key, $this->visibleColumns());
    }
}
