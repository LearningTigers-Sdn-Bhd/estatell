<?php

namespace App\Actions\Meals;

use App\Models\MealPeriod;
use App\Models\Property;
use App\Models\User;
use App\Services\Meals\MealPeriodOverlap;
use Illuminate\Support\Facades\DB;

class CreateMealPeriod
{
    public function __construct(private MealPeriodOverlap $overlap) {}

    /**
     * Add a meal period. Periods are listed by start time, so it lands in place.
     *
     * Returns null when an active period now overlaps it.
     *
     * @param  array{name: string, starts_at: string, ends_at: string, active: bool}  $attributes  Validated fields.
     */
    public function __invoke(Property $property, User $actor, array $attributes): ?MealPeriod
    {
        return DB::transaction(function () use ($property, $actor, $attributes): ?MealPeriod {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            if ($attributes['active'] && $this->overlap->exists($property, $attributes['starts_at'], $attributes['ends_at'])) {
                return null;
            }

            $mealPeriod = $property->mealPeriods()->create($attributes);

            activity()
                ->performedOn($mealPeriod)
                ->causedBy($actor)
                ->event('meal_period.created')
                ->withProperties(['property_id' => $property->id, 'name' => $mealPeriod->name])
                ->log('Meal period created');

            return $mealPeriod;
        });
    }
}
