<?php

namespace App\Data\Meals;

use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use Spatie\LaravelData\Data;

/** A rate plan inclusion that serves a meal, as the entitlement resolver reads it. */
class MealInclusionData extends Data
{
    public function __construct(
        public int $mealPeriodId,
        public string $mealPeriodStartsAt,
        public InclusionStart $startsOn,
        public InclusionEnd $endsOn,
    ) {}
}
