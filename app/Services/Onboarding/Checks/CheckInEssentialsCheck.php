<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\StepState;
use App\Models\Property;

/**
 * Done when the check-in and check-out times are set and the default terms document has a published version. Both are
 * seeded, so a new property shows Done before the owner opens the section.
 */
class CheckInEssentialsCheck implements StepCheck
{
    public function state(Property $property): StepState
    {
        $settings = $property->settings;

        if ($settings === null || blank($settings->check_in_time) || blank($settings->check_out_time)) {
            return StepState::NotStarted;
        }

        $published = $property->termsDocuments()
            ->where('is_default', true)
            ->whereHas('versions')
            ->exists();

        return $published ? StepState::Done : StepState::NotStarted;
    }
}
