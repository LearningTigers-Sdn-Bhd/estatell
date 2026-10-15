<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\StepState;
use App\Models\Property;

/**
 * Done when the property has at least one active room type, sold by room or by bed. NotStarted otherwise. The check has
 * no middle state: one room type is enough, and the form has no way to save half a row.
 */
class RoomTypesCheck implements StepCheck
{
    public function state(Property $property): StepState
    {
        return $property->roomTypes()->active()->exists() ? StepState::Done : StepState::NotStarted;
    }
}
