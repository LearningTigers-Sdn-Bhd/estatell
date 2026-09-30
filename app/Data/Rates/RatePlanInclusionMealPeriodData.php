<?php

namespace App\Data\Rates;

use App\Enums\DefaultMealPeriod;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Models\MealPeriod;
use Spatie\LaravelData\Data;

/** A meal period an inclusion can serve, with the dates the form prefills when it is picked. */
class RatePlanInclusionMealPeriodData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
        public InclusionStart $defaultStartsOn,
        public InclusionEnd $defaultEndsOn,
    ) {}

    public static function fromModel(MealPeriod $mealPeriod): self
    {
        $default = DefaultMealPeriod::fromName($mealPeriod->name);

        return new self(
            id: $mealPeriod->id,
            name: $mealPeriod->name,
            active: $mealPeriod->active,
            defaultStartsOn: $default?->inclusionStart() ?? InclusionStart::ArrivalDay,
            defaultEndsOn: $default?->inclusionEnd() ?? InclusionEnd::DepartureDay,
        );
    }
}
