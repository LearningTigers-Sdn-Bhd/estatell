<?php

namespace App\Data\Meals;

use App\Models\MealPeriod;
use Spatie\LaravelData\Data;

/** One row of the meal-period list and the values of its edit form. */
class MealPeriodData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $startsAt,
        public string $endsAt,
        public bool $active,
    ) {}

    public static function fromModel(MealPeriod $mealPeriod): self
    {
        return new self(
            id: $mealPeriod->id,
            name: $mealPeriod->name,
            startsAt: substr($mealPeriod->starts_at, 0, 5),
            endsAt: substr($mealPeriod->ends_at, 0, 5),
            active: $mealPeriod->active,
        );
    }
}
