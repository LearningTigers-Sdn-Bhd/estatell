<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\StepState;
use App\Models\Property;
use App\Models\PropertySetting;

/**
 * Done when the property has a type, name, address line 1, city, country and a settings row. SavePropertyBasics and
 * the owner setup write these. InProgress when some of them are set.
 */
class BasicsCheck implements StepCheck
{
    public function state(Property $property): StepState
    {
        $filled = [
            $property->type !== null,
            filled($property->name),
            filled($property->address_line1),
            filled($property->city),
            filled($property->country_code),
            PropertySetting::query()->where('property_id', $property->id)->exists(),
        ];

        return match (true) {
            ! in_array(false, $filled, true) => StepState::Done,
            in_array(true, $filled, true) => StepState::InProgress,
            default => StepState::NotStarted,
        };
    }
}
