<?php

namespace App\Data\Property;

use App\Models\PropertyAgeGroup;
use Spatie\LaravelData\Data;

/** One row of the age-group form. */
class PropertyAgeGroupData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
        public int $minAge,
        public int $maxAge,
    ) {}

    public static function fromModel(PropertyAgeGroup $ageGroup): self
    {
        return new self(
            id: $ageGroup->id,
            label: $ageGroup->label,
            minAge: $ageGroup->min_age,
            maxAge: $ageGroup->max_age,
        );
    }
}
