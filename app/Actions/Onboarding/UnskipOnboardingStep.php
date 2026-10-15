<?php

namespace App\Actions\Onboarding;

use App\Enums\OnboardingStep;
use App\Models\OnboardingSkip;
use App\Models\Property;

class UnskipOnboardingStep
{
    /**
     * Remove a skip, so the step is judged by its check again. Returns false when the step was not skipped.
     */
    public function __invoke(Property $property, OnboardingStep $step): bool
    {
        return OnboardingSkip::query()
            ->where('property_id', $property->id)
            ->where('step', $step)
            ->delete() > 0;
    }
}
