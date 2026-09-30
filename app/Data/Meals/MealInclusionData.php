<?php

namespace App\Data\Meals;

use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Models\RatePlanInclusion;
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

    /**
     * Load the inclusion's `mealPeriod` first. The inclusion must serve a meal.
     */
    public static function fromInclusion(RatePlanInclusion $inclusion): self
    {
        return new self(
            mealPeriodId: $inclusion->meal_period_id,
            mealPeriodStartsAt: $inclusion->mealPeriod->starts_at,
            startsOn: $inclusion->starts_on,
            endsOn: $inclusion->ends_on,
        );
    }
}
