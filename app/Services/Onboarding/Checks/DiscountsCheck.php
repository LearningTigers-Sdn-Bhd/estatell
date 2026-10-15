<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\StepState;
use App\Models\Property;

/**
 * Done when at least one discount is active. Optional, so it never blocks the wizard; "Skip" makes the step Done
 * through OnboardingProgress.
 */
class DiscountsCheck implements StepCheck
{
    public function state(Property $property): StepState
    {
        return $property->discounts()->active()->exists()
            ? StepState::Done
            : StepState::NotStarted;
    }
}
