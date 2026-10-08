<?php

namespace App\Services\Meals;

/**
 * The guests to feed at one meal period on one date. `guests` is adults plus children.
 */
class MealCount
{
    public readonly int $guests;

    public function __construct(
        public readonly string $date,
        public readonly int $mealPeriodId,
        public readonly string $mealPeriodName,
        public readonly int $adults,
        public readonly int $children,
    ) {
        $this->guests = $adults + $children;
    }
}
