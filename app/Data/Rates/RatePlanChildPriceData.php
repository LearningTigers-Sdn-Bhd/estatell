<?php

namespace App\Data\Rates;

use App\Enums\AgeBandPricing;
use App\Models\RatePlanChildPrice;
use Spatie\LaravelData\Data;

/** One row of the plan editor's Children tab: an age group and how the plan prices it. */
class RatePlanChildPriceData extends Data
{
    public function __construct(
        public int $ageGroupId,
        public string $label,
        public int $minAge,
        public int $maxAge,
        public AgeBandPricing $pricing,
        public ?string $value,
    ) {}

    /**
     * Load the price's `ageGroup` first.
     */
    public static function fromModel(RatePlanChildPrice $childPrice): self
    {
        return new self(
            ageGroupId: $childPrice->property_age_group_id,
            label: $childPrice->ageGroup->label,
            minAge: $childPrice->ageGroup->min_age,
            maxAge: $childPrice->ageGroup->max_age,
            pricing: $childPrice->pricing,
            value: $childPrice->value,
        );
    }
}
