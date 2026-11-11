<?php

namespace App\Services\Concierge;

use App\Enums\ConciergeStep;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Services\FrontDesk\SelfCheckInPayment;
use App\Services\Kiosk\KioskStays;
use App\Services\Kiosk\KioskWalkInHolds;
use App\Services\Registration\RegistrationCardFieldRules;
use App\Services\Registration\RegistrationCardRequirement;

/**
 * The step the guest is on, from the state of the stay. The order is: details and ID, the registration card (when the
 * property requires it), payment (the same paid-enough rule as the kiosk), check-in, then the key. The card step covers
 * the primary guest only; a card still missing for another guest, a stay that is no longer open and a stay with no guest
 * all answer `Desk`. Reads only.
 */
class ConciergeProgress
{
    public function __construct(
        private ConciergeStays $concierge,
        private KioskStays $stays,
        private RegistrationCardRequirement $cards,
        private RegistrationCardFieldRules $fieldRules,
        private SelfCheckInPayment $payment,
        private KioskWalkInHolds $holds,
    ) {}

    public function for(ReservationUnit $stay): ConciergeStep
    {
        $stay->loadMissing('property.settings');

        if ($stay->status === UnitStatus::CheckedOut) {
            return ConciergeStep::CheckedOut;
        }

        if ($stay->status === UnitStatus::CheckedIn) {
            return $this->concierge->checkedInByConcierge($stay) ? ConciergeStep::Key : ConciergeStep::Home;
        }

        if ($this->holds->expired($stay->property, $stay->id)) {
            return ConciergeStep::HoldExpired;
        }

        if ($stay->status !== UnitStatus::Confirmed) {
            return ConciergeStep::Desk;
        }

        $window = $this->stays->window($stay);

        if ($window === KioskStays::EARLY) {
            return ConciergeStep::NotOpen;
        }

        $guest = $this->stays->guest($stay);

        if ($window !== KioskStays::OPEN || $guest === null) {
            return ConciergeStep::Desk;
        }

        $property = $stay->property;

        if (! $guest->documents()->exists() || (blank($guest->nationality_code) && $this->fieldRules->nationalityIsNeededForTax($property))) {
            return ConciergeStep::Identity;
        }

        if ($property->settings?->card_required_for_check_in) {
            $missing = $this->cards->missingFor($stay, $property);

            if ($missing->isNotEmpty()) {
                return $missing->contains(fn ($unitGuest): bool => $unitGuest->is_primary) ? ConciergeStep::Card : ConciergeStep::Desk;
            }
        }

        return $this->payment->isPaidEnough($stay) ? ConciergeStep::CheckIn : ConciergeStep::Payment;
    }
}
