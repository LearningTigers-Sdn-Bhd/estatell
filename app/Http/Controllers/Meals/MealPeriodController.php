<?php

namespace App\Http\Controllers\Meals;

use App\Actions\Meals\SaveMealPeriods;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meals\SaveMealPeriodsRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class MealPeriodController extends Controller
{
    public function update(SaveMealPeriodsRequest $request, Property $property, SaveMealPeriods $saveMealPeriods): RedirectResponse
    {
        if (! $saveMealPeriods($property, $request->user(), $request->periods())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The meal periods changed. Reload the page and try again.')]);

            return to_route('settings.meal-periods', $property);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Meal periods saved.')]);

        return to_route('settings.meal-periods', $property);
    }
}
