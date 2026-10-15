<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\StepState;
use App\Models\Property;

/**
 * Stub: the real check is not built yet, so this always returns NotStarted. A later commit replaces it with the rule in
 * docs/17-onboarding/02-wizard-steps.md.
 */
class TeamCheck implements StepCheck
{
    public function state(Property $property): StepState
    {
        return StepState::NotStarted;
    }
}
