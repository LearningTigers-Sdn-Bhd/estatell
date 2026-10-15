<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\StepState;
use App\Models\Property;

/**
 * Done when the property has at least one OTA list row. The step is optional: a skip is turned into Done by
 * OnboardingProgress before this check runs, and a missing OTA list never blocks go-live.
 */
class ChannelManagerCheck implements StepCheck
{
    public function state(Property $property): StepState
    {
        return $property->otaCredentials()->exists() ? StepState::Done : StepState::NotStarted;
    }
}
