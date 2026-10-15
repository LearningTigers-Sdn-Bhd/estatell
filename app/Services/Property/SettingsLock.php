<?php

namespace App\Services\Property;

use App\Enums\OnboardingStep;
use App\Enums\StepState;
use App\Models\Property;
use App\Services\Onboarding\OnboardingProgress;

/**
 * Settings that stay fixed once a property has gone live, because money and business dates depend on them, and the
 * country, which also stays fixed once setup has started.
 */
class SettingsLock
{
    public function __construct(private OnboardingProgress $progress) {}

    public function locksCurrencyAndTimeZone(Property $property): bool
    {
        return $property->went_live_at !== null;
    }

    /**
     * Country, currency and time zone are plain fields on Basics only while the property is not live and every other
     * onboarding section is Not started (or Locked behind Basics). After that, Change country (a later item) is the way.
     */
    public function locksCountry(Property $property): bool
    {
        return $this->locksCurrencyAndTimeZone($property) || $this->setupStarted($property);
    }

    /**
     * Whether any section other than Basics is past Not started. Payment methods and Discounts do not count: the
     * seeded methods and the active starter discount make them Done before the owner opens them.
     */
    public function setupStarted(Property $property): bool
    {
        $ignored = [OnboardingStep::Basics->value, OnboardingStep::PaymentMethods->value, OnboardingStep::Discounts->value];

        foreach ($this->progress->states($property) as $step => $state) {
            if (! in_array($step, $ignored, true) && ! in_array($state, [StepState::NotStarted, StepState::Locked], true)) {
                return true;
            }
        }

        return false;
    }
}
