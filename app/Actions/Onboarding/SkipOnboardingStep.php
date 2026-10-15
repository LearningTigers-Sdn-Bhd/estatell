<?php

namespace App\Actions\Onboarding;

use App\Enums\OnboardingStep;
use App\Enums\StepState;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\OnboardingProgress;

class SkipOnboardingStep
{
    public function __construct(private OnboardingProgress $progress) {}

    /**
     * Mark an optional step, or Taxes ("We don't charge tax"), as skipped. Returns false, and writes nothing, when the
     * step cannot be skipped, is locked, or is already skipped.
     */
    public function __invoke(Property $property, OnboardingStep $step, User $actor): bool
    {
        if (! $step->isSkippable() || $this->progress->state($property, $step) === StepState::Locked) {
            return false;
        }

        $skip = OnboardingSkip::query()->firstOrCreate(
            ['property_id' => $property->id, 'step' => $step],
            ['skipped_by' => $actor->id, 'skipped_at' => now()],
        );

        return $skip->wasRecentlyCreated;
    }
}
