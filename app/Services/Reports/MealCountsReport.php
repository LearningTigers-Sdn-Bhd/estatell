<?php

namespace App\Services\Reports;

use App\Data\Reports\MealCountRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Models\Property;
use App\Services\Meals\MealCount;
use App\Services\Meals\MealCounts;
use Carbon\CarbonImmutable;

/**
 * The kitchen meal counts: one row for each date and meal period that has guests, from `MealCounts::between()`. Rows
 * come in date order and, inside a date, in the order of the meal start times. Future dates are counted from the
 * bookings as they are now. See docs/22-reports/01-core-reports.md and docs/07-meals/02-meal-entitlements.md.
 */
class MealCountsReport implements HasTotals, Report
{
    public function __construct(private MealCounts $mealCounts) {}

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<MealCountRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $rows = $this->mealCounts
            ->between($property, CarbonImmutable::parse($filters->from), CarbonImmutable::parse($filters->to))
            ->map(fn (MealCount $count): MealCountRow => new MealCountRow(
                key: "{$count->date}:{$count->mealPeriodId}",
                date: $count->date,
                meal: $count->mealPeriodName,
                adults: $count->adults,
                children: $count->children,
                total: $count->guests,
            ))
            ->all();

        if ($rowKeys !== null) {
            $wanted = array_flip(array_map('strval', $rowKeys));
            $rows = array_values(array_filter($rows, fn (MealCountRow $row): bool => isset($wanted[$row->key])));
        }

        return array_values($rows);
    }

    /**
     * The adults, children and guests of the rows.
     *
     * @param  list<MealCountRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'date' => null,
            'meal' => null,
            'adults' => array_sum(array_map(fn (MealCountRow $row): int => $row->adults, $rows)),
            'children' => array_sum(array_map(fn (MealCountRow $row): int => $row->children, $rows)),
            'total' => array_sum(array_map(fn (MealCountRow $row): int => $row->total, $rows)),
        ]);
    }
}
