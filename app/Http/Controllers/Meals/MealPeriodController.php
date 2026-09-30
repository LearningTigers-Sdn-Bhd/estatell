<?php

namespace App\Http\Controllers\Meals;

use App\Actions\Meals\CreateMealPeriod;
use App\Actions\Meals\UpdateMealPeriod;
use App\Data\Meals\MealPeriodData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meals\MealPeriodRequest;
use App\Models\MealPeriod;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MealPeriodController extends Controller
{
    /** Load the property's meal periods, sorted by start time, once for the page. */
    public function index(Property $property): Response
    {
        return Inertia::render('settings/meal-periods/index', [
            'mealPeriods' => $property->mealPeriods()
                ->ordered()
                ->get()
                ->map(fn (MealPeriod $mealPeriod): MealPeriodData => MealPeriodData::fromModel($mealPeriod)),
        ]);
    }

    public function store(MealPeriodRequest $request, Property $property, CreateMealPeriod $createMealPeriod): RedirectResponse
    {
        $mealPeriod = $createMealPeriod($property, $request->user(), $request->validated());

        if (! $mealPeriod instanceof MealPeriod) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The meal periods changed. Reload the page and try again.')]);

            return to_route('settings.meal-periods.index', $property);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created.', ['name' => $mealPeriod->name])]);

        return to_route('settings.meal-periods.index', $property);
    }

    public function update(
        MealPeriodRequest $request,
        Property $property,
        MealPeriod $mealPeriod,
        UpdateMealPeriod $updateMealPeriod,
    ): RedirectResponse {
        if (! $updateMealPeriod($request->user(), $mealPeriod, $request->validated())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The meal periods changed. Reload the page and try again.')]);

            return to_route('settings.meal-periods.index', $property);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $request->string('name')->trim()])]);

        return to_route('settings.meal-periods.index', $property);
    }
}
