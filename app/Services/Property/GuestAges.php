<?php

namespace App\Services\Property;

use App\Models\Property;
use App\Models\PropertyAgeGroup;

/**
 * Splits a party by the property's age groups: a child whose age is in no group counts as an adult, as the rate
 * quote does. A property with no groups has adults only. See docs/02-property/04-age-groups.md.
 */
class GuestAges
{
    /**
     * @param  list<int>  $ages
     * @return array{adults: int, childAges: list<int>}
     */
    public function split(Property $property, int $adults, array $ages): array
    {
        $groups = PropertyAgeGroup::query()->where('property_id', $property->id)->get();
        $childAges = [];

        foreach ($ages as $age) {
            if ($groups->contains(fn (PropertyAgeGroup $group): bool => $age >= $group->min_age && $age <= $group->max_age)) {
                $childAges[] = $age;
            } else {
                $adults++;
            }
        }

        return ['adults' => $adults, 'childAges' => $childAges];
    }
}
