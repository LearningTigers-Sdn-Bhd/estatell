<?php

namespace App\Data\Meals;

use Spatie\LaravelData\Data;

/** Meals to prepare for one date and one meal period. */
class MealEntitlementData extends Data
{
    public function __construct(
        public string $date,
        public int $mealPeriodId,
        public int $guests,
    ) {}
}
