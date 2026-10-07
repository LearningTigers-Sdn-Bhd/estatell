<?php

namespace App\Data\Reports;

use Spatie\LaravelData\Data;

/**
 * The one total row of a report, keyed by column key. A figure is a count (int), a money or percent amount (a decimal
 * string with two decimals) or null when the column has no total. The screen formats the figures; the PDF and the
 * spreadsheet read `cellsFor()` and `valuesFor()`, which put "Total" in the first visible column that has no figure.
 */
class ReportTotals extends Data
{
    /**
     * @param  array<string, int|string|null>  $figures
     */
    public function __construct(public array $figures) {}

    /**
     * The display text of the visible columns.
     *
     * @param  list<ReportLayoutColumnData>  $columns
     * @return array<string, string>
     */
    public function cellsFor(array $columns): array
    {
        $cells = [];
        $label = $this->labelKey($columns);

        foreach ($columns as $column) {
            $figure = $this->figures[$column->key] ?? null;

            $cells[$column->key] = match (true) {
                $figure === null => $column->key === $label ? __('Total') : '',
                is_int($figure) => (string) $figure,
                default => number_format((float) $figure, 2),
            };
        }

        return $cells;
    }

    /**
     * The typed values of the visible columns for a spreadsheet.
     *
     * @param  list<ReportLayoutColumnData>  $columns
     * @return array<string, int|float|string>
     */
    public function valuesFor(array $columns): array
    {
        $values = [];
        $label = $this->labelKey($columns);

        foreach ($columns as $column) {
            $figure = $this->figures[$column->key] ?? null;

            $values[$column->key] = match (true) {
                $figure === null => $column->key === $label ? __('Total') : '',
                is_int($figure) => $figure,
                default => round((float) $figure, 2),
            };
        }

        return $values;
    }

    /**
     * The key of the first visible column without a figure, which carries the "Total" label.
     *
     * @param  list<ReportLayoutColumnData>  $columns
     */
    private function labelKey(array $columns): ?string
    {
        foreach ($columns as $column) {
            if (($this->figures[$column->key] ?? null) === null) {
                return $column->key;
            }
        }

        return null;
    }
}
