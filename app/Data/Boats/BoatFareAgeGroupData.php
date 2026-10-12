<?php

namespace App\Data\Boats;

use App\Models\PropertyAgeGroup;
use Spatie\LaravelData\Data;

/** An age group of the property, for the fare Sheet's Children rows when a fare is new. */
class BoatFareAgeGroupData extends Data
{
    public function __construct(
        public int $ageGroupId,
        public string $label,
        public int $minAge,
        public int $maxAge,
    ) {}

    public static function fromModel(PropertyAgeGroup $ageGroup): self
    {
        return new self(
            ageGroupId: $ageGroup->id,
            label: $ageGroup->label,
            minAge: $ageGroup->min_age,
            maxAge: $ageGroup->max_age,
        );
    }
}
