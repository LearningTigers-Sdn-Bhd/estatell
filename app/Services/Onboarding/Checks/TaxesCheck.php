<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\StepState;
use App\Models\Property;

/**
 * Done when at least one tax is enabled, anywhere. "We don't charge tax" is a skip row, which OnboardingProgress turns
 * into Done before this check runs.
 */
class TaxesCheck implements StepCheck
{
    public function state(Property $property): StepState
    {
        return $property->taxes()->where('enabled', true)->exists()
            ? StepState::Done
            : StepState::NotStarted;
    }
}
