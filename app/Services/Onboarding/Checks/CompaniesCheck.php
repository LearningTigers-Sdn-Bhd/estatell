<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\CompanyStatus;
use App\Enums\StepState;
use App\Models\Property;

/**
 * Done when the property has at least one Active company. A skip is turned into Done by OnboardingProgress before this
 * check runs.
 */
class CompaniesCheck implements StepCheck
{
    public function state(Property $property): StepState
    {
        return $property->companies()->where('status', CompanyStatus::Active)->exists()
            ? StepState::Done
            : StepState::NotStarted;
    }
}
