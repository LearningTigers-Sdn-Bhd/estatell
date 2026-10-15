<?php

namespace App\Actions\Onboarding;

use App\Models\Property;

/**
 * Records that the owner did something in the onboarding wizard. The next reminder then counts from now, and the
 * reminder stage starts again at 0.
 */
class RecordOnboardingActivity
{
    public function __invoke(Property $property): void
    {
        $property->forceFill([
            'onboarding_last_activity_at' => now(),
            'onboarding_reminder_stage' => 0,
        ])->save();
    }
}
