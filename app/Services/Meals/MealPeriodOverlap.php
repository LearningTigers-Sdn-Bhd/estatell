<?php

namespace App\Services\Meals;

use App\Models\MealPeriod;
use App\Models\Property;

class MealPeriodOverlap
{
    /**
     * Whether a time range overlaps an active meal period of the property.
     * Ranges that only touch (one ends when the other starts) do not overlap.
     *
     * @param  int|null  $ignoreId  The period being edited.
     */
    public function exists(Property $property, string $startsAt, string $endsAt, ?int $ignoreId = null): bool
    {
        return MealPeriod::query()
            ->whereBelongsTo($property)
            ->active()
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }
}
