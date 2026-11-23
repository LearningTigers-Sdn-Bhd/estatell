<?php

namespace App\Data\Pos;

use Spatie\LaravelData\Data;

/**
 * The meal check-in list for one date and meal period, with the three header totals.
 */
class MealCheckInListData extends Data
{
    /**
     * @param  list<MealCheckInRow>  $rows
     */
    public function __construct(
        public int $totalEntitled,
        public int $checkedIn,
        public int $remaining,
        public array $rows,
    ) {}
}
