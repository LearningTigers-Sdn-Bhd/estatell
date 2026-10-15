<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\StepState;
use App\Models\Property;

/**
 * Reads one onboarding step's data and says how far it has got. Checks only read. They never write, and they ignore
 * locks and skips, which OnboardingProgress combines with the result.
 */
interface StepCheck
{
    /**
     * Returns NotStarted, InProgress or Done. Never Locked or NeedsAttention.
     */
    public function state(Property $property): StepState;
}
