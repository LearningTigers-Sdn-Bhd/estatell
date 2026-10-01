<?php

namespace App\Services\Meals;

class MealPeriodOverlap
{
    /**
     * Positions of the active rows whose time range overlaps an earlier active row.
     * Ranges that only touch (one ends when the other starts) do not overlap. Inactive rows are ignored.
     *
     * @param  list<array{starts_at: string, ends_at: string, active: bool}>  $rows  Times are `HH:MM`.
     * @return list<int>
     */
    public function overlappingPositions(array $rows): array
    {
        $positions = [];

        foreach ($rows as $position => $row) {
            if (! $row['active']) {
                continue;
            }

            foreach (array_slice($rows, 0, $position, true) as $earlier) {
                if ($earlier['active'] && $earlier['starts_at'] < $row['ends_at'] && $earlier['ends_at'] > $row['starts_at']) {
                    $positions[] = $position;

                    break;
                }
            }
        }

        return $positions;
    }
}
