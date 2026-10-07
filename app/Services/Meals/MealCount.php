<?php

namespace App\Services\Meals;

/**
 * The guests to feed at one meal period on one date.
 */
class MealCount
{
    public function __construct(
        public readonly int $mealPeriodId,
        public readonly string $mealPeriodName,
        public readonly int $guests,
    ) {}
}
