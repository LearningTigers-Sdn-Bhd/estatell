<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportLayoutColumnData;
use App\Data\Reports\ReportLayoutData;
use App\Enums\ColumnAlign;
use App\Enums\PageOrientation;
use App\Enums\ReportKey;
use App\Models\Property;
use App\Models\ReportLayout;
use App\Models\ReportLayoutColumn;
use Illuminate\Validation\ValidationException;

/**
 * Merges the stored layout of a property with the columns in code. With no stored row the code defaults apply. Stored
 * columns keep their order; a column added in code later goes at the end with its default visibility; a stored key
 * that code no longer has is ignored. It reads only. See docs/22-reports/01-core-reports.md.
 */
class ReportLayoutResolver
{
    public function resolve(Property $property, ReportKey $report): ReportLayoutData
    {
        $layout = ReportLayout::query()
            ->where('property_id', $property->id)
            ->where('report_key', $report->value)
            ->with('columns')
            ->first();

        if ($layout === null) {
            return $this->defaults($report);
        }

        /** @var array<string, ReportColumn> $byKey */
        $byKey = [];

        foreach ($report->columns() as $column) {
            $byKey[$column->key] = $column;
        }

        $columns = [];

        foreach ($layout->columns as $stored) {
            /** @var ReportLayoutColumn $stored */
            if (isset($byKey[$stored->column_key])) {
                $columns[] = ReportLayoutColumnData::fromColumn($byKey[$stored->column_key], $stored->is_visible, $stored->align);
                unset($byKey[$stored->column_key]);
            }
        }

        foreach ($byKey as $column) {
            $columns[] = ReportLayoutColumnData::fromColumn($column, $column->defaultVisible);
        }

        return new ReportLayoutData($layout->orientation, $columns);
    }

    /**
     * The layout the code defines: the default orientation and the columns in their default order.
     */
    public function defaults(ReportKey $report): ReportLayoutData
    {
        return new ReportLayoutData(
            $report->defaultOrientation(),
            array_map(fn (ReportColumn $column): ReportLayoutColumnData => ReportLayoutColumnData::fromColumn($column, $column->defaultVisible), $report->columns()),
        );
    }

    /**
     * Builds a layout from a draft list, which must hold exactly the columns of the report, each once, with at least one
     * visible. Used to check a layout before it is saved or previewed.
     *
     * @param  list<array{key: string, visible: bool, align?: string|null}>  $draft
     *
     * @throws ValidationException
     */
    public function fromDraft(ReportKey $report, PageOrientation $orientation, array $draft): ReportLayoutData
    {
        $codeKeys = array_map(fn (ReportColumn $column): string => $column->key, $report->columns());
        $draftKeys = array_map(fn (array $item): string => $item['key'], $draft);

        $sameSet = count($draftKeys) === count($codeKeys)
            && array_diff($draftKeys, $codeKeys) === []
            && count(array_unique($draftKeys)) === count($draftKeys);

        if (! $sameSet) {
            throw ValidationException::withMessages(['columns' => __('The columns do not match the report. Reload the page and try again.')]);
        }

        if (! in_array(true, array_column($draft, 'visible'), true)) {
            throw ValidationException::withMessages(['columns' => __('Show at least one column.')]);
        }

        $visibility = array_column($draft, 'visible', 'key');
        $alignment = array_column($draft, 'align', 'key');
        $columns = [];

        foreach ($draftKeys as $key) {
            $column = collect($report->columns())->firstWhere('key', $key);
            $columns[] = ReportLayoutColumnData::fromColumn($column, (bool) $visibility[$key], ColumnAlign::tryFrom((string) ($alignment[$key] ?? '')));
        }

        return new ReportLayoutData($orientation, $columns);
    }
}
