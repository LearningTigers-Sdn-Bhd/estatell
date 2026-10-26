<?php

namespace App\Services\Boats;

use App\Data\Meals\MealPeriodData;
use App\Enums\BoatDirection;
use App\Models\BoatDeparture;
use App\Services\Meals\MealTimeRule;

/**
 * The meals a guest on a departure can get on the arrival or departure day, from its property-side time and the meal
 * period times. Shows what the boat time allows, not what a rate plan includes. Reads only.
 */
class DepartureMeals
{
    /**
     * @param  array<int, MealPeriodData>  $mealPeriods  The active periods, in start time order.
     * @return list<string> The matching period names, in the same order.
     */
    public function forDeparture(BoatDeparture $departure, array $mealPeriods): array
    {
        $time = $departure->direction->propertyTime($departure);
        $matches = array_filter($mealPeriods, fn (MealPeriodData $mealPeriod): bool => $departure->direction === BoatDirection::In
            ? MealTimeRule::countsOnArrival($time, $mealPeriod->endsAt)
            : MealTimeRule::countsOnDeparture($time, $mealPeriod->startsAt));

        return array_values(array_map(fn (MealPeriodData $mealPeriod): string => $mealPeriod->name, $matches));
    }
}
