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
 * columns keep their order; a column added in code later goes at the end with its default visibility, except a `tax:`
 * column, which goes before the stored `adjustments` column; a stored key that code no longer has is ignored. It
 * reads only. See docs/22-reports/01-core-reports.md.
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
            return $this->defaults($property, $report);
        }

        return $this->merge($layout, $report->columnsFor($property));
    }

    /**
     * Applies a stored layout to the columns the code has for the report.
     *
     * @param  list<ReportColumn>  $codeColumns
     */
    public function merge(ReportLayout $layout, array $codeColumns): ReportLayoutData
    {
        /** @var array<string, ReportColumn> $byKey */
        $byKey = [];

        foreach ($codeColumns as $column) {
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
            $data = ReportLayoutColumnData::fromColumn($column, $column->defaultVisible);

            if (str_starts_with($column->key, 'tax:')) {
                array_splice($columns, $this->taxColumnPosition($columns), 0, [$data]);
            } else {
                $columns[] = $data;
            }
        }

        return new ReportLayoutData($layout->orientation, $columns);
    }

    /**
     * Where a new tax column goes: right before the stored Adjustments column, or at the end when there is none. A
     * second new tax column then lands after the first, so several keep code order.
     *
     * @param  list<ReportLayoutColumnData>  $columns
     */
    private function taxColumnPosition(array $columns): int
    {
        foreach ($columns as $index => $column) {
            if ($column->key === 'adjustments') {
                return $index;
            }
        }

        return count($columns);
    }

    /**
     * The layout the code defines: the default orientation and the columns in their default order.
     */
    public function defaults(Property $property, ReportKey $report): ReportLayoutData
    {
        return new ReportLayoutData(
            $report->defaultOrientation(),
            array_map(fn (ReportColumn $column): ReportLayoutColumnData => ReportLayoutColumnData::fromColumn($column, $column->defaultVisible), $report->columnsFor($property)),
        );
    }

    /**
     * Builds a layout from a draft list, which must hold exactly the columns of the report that are on for the property,
     * each once, with at least one visible. A column whose feature is off may be in the draft and is dropped. Used to
     * check a layout before it is saved or previewed.
     *
     * @param  list<array{key: string, visible: bool, align?: string|null}>  $draft
     *
     * @throws ValidationException
     */
    public function fromDraft(Property $property, ReportKey $report, PageOrientation $orientation, array $draft): ReportLayoutData
    {
        $columns = $report->columnsFor($property);
        $offKeys = array_diff(array_column($report->columns(), 'key'), array_column($columns, 'key'));
        $draft = array_values(array_filter($draft, fn (array $item): bool => ! in_array($item['key'], $offKeys, true)));
        $codeKeys = array_map(fn (ReportColumn $column): string => $column->key, $columns);
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
        $layoutColumns = [];

        foreach ($draftKeys as $key) {
            $column = collect($columns)->firstWhere('key', $key);
            $layoutColumns[] = ReportLayoutColumnData::fromColumn($column, (bool) $visibility[$key], ColumnAlign::tryFrom((string) ($alignment[$key] ?? '')));
        }

        return new ReportLayoutData($orientation, $layoutColumns);
    }
}
