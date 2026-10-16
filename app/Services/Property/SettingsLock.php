<?php

namespace App\Services\Property;

use App\Enums\OnboardingStep;
use App\Enums\PropertyStatus;
use App\Enums\StepState;
use App\Models\Property;
use App\Services\Onboarding\GoLiveRequests;
use App\Services\Onboarding\OnboardingProgress;

/**
 * Settings that stay fixed once a property has gone live, because money and business dates depend on them, and the
 * country, which stays fixed once setup has started unless the owner uses Change country.
 */
class SettingsLock
{
    public function __construct(
        private OnboardingProgress $progress,
        private GoLiveRequests $goLiveRequests,
    ) {}

    public function locksCurrencyAndTimeZone(Property $property): bool
    {
        return $property->went_live_at !== null;
    }

    /**
     * Country, currency and time zone are plain fields on Basics only while the property is not live and every other
     * onboarding section is Not started (or Locked behind Basics). Once setup started they show read-only, and Change
     * country unlocks them (see canChangeCountry).
     */
    public function locksCountry(Property $property): bool
    {
        return $this->locksCurrencyAndTimeZone($property) || $this->setupStarted($property);
    }

    /**
     * Whether the country can change: the property is still in onboarding and no go-live request is pending. Holds
     * before setup starts (plain fields) and after it (Change country). Live, suspended and pending properties cannot.
     */
    public function canChangeCountry(Property $property): bool
    {
        return ! $this->locksCurrencyAndTimeZone($property)
            && $property->status === PropertyStatus::Onboarding
            && $this->goLiveRequests->pending($property) === null;
    }

    /**
     * Whether any section other than Basics is past Not started. Payment methods, Discounts and Check-in essentials do
     * not count: the seeded methods, the active starter discount and the seeded times and terms make them Done before
     * the owner opens them. Team does not count either: staff do not depend on the country.
     */
    public function setupStarted(Property $property): bool
    {
        $ignored = [OnboardingStep::Basics->value, OnboardingStep::PaymentMethods->value, OnboardingStep::Discounts->value, OnboardingStep::CheckInEssentials->value, OnboardingStep::Team->value];

        foreach ($this->progress->states($property) as $step => $state) {
            if (! in_array($step, $ignored, true) && ! in_array($state, [StepState::NotStarted, StepState::Locked], true)) {
                return true;
            }
        }

        return false;
    }
}
