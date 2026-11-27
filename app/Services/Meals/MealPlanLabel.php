<?php

namespace App\Services\Meals;

use App\Models\MealPeriod;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;

/**
 * The meals a rate plan includes, as one line for reports: "Breakfast, Lunch, Dinner", in meal time order. Uses the
 * same source as Meal counts: the plan's inclusions that name a meal period. Null when the plan includes no meal.
 */
class MealPlanLabel
{
    /**
     * Expects `inclusions.mealPeriod` to be loaded on the plan.
     */
    public function forRatePlan(?RatePlan $ratePlan): ?string
    {
        $names = $ratePlan?->inclusions
            ->map(fn (RatePlanInclusion $inclusion): ?MealPeriod => $inclusion->mealPeriod)
            ->filter()
            ->unique('id')
            ->sortBy(fn (MealPeriod $period): string => $period->starts_at)
            ->map(fn (MealPeriod $period): string => $period->name)
            ->implode(', ');

        return $names === null || $names === '' ? null : $names;
    }
}
