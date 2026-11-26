<?php

namespace App\Actions\Concierge;

use App\Actions\Kiosk\IssueBookingPreCheckInToken;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Services\Concierge\ConciergeLookupLockout;
use App\Services\Concierge\ConciergeStays;

class OpenConciergeStay
{
    public function __construct(
        private ConciergeLookupLockout $lockout,
        private ConciergeStays $stays,
        private IssueBookingPreCheckInToken $issueToken,
    ) {}

    /**
     * Open a stay from the lobby page by booking code, or, when the property allows it, by the name on the booking (`ConciergeStays::byGuestName`). The booking code is the reservation's `short_code`, the one the guest is given, not the staff `reference`. When a name matches a returning guest who already has an ID document saved, their ID number must match before a link is issued (`id_confirmation_required` / `id_mismatch`). The lockout is the main guard against guessing. Every lookup takes an attempt from the lockout, and a
     * stay that is found gives it back. The link it returns is the stay's own pre-check-in link, so every later page
     * works from one signed token and no session. Refusals are `rate_limited`, `id_confirmation_required`, `id_mismatch`, `not_found` (wrong code or another property), and, for a booking that exists here, `multiple_rooms`, `checked_out` and `closed`; nothing is written for any.
     */
    public function __invoke(Property $property, string $reference, string $address, bool $allowName = true, ?string $idNumber = null): ConciergeLookupResult
    {
        if (! $this->lockout->attempt($property, $address, $reference)) {
            return ConciergeLookupResult::refused('rate_limited', $this->lockout->retryAfter($property, $address, $reference));
        }

        $stay = $this->stays->byShortCode($property, $reference);

        if ($stay === null && $allowName && $property->settings?->concierge_name_check_in_enabled) {
            $stay = $this->stays->byGuestName($property, $reference);

            if ($stay !== null && $this->stays->guestHasIdDocument($stay)) {
                if (blank($idNumber)) {
                    return ConciergeLookupResult::refused('id_confirmation_required');
                }

                if (! $this->stays->matchesGuestIdNumber($stay, $idNumber)) {
                    return ConciergeLookupResult::refused('id_mismatch');
                }
            }
        }

        if ($stay === null) {
            return ConciergeLookupResult::refused($this->stays->lookupFailure($property, $reference));
        }

        if (! $this->lockout->found($property, $address)) {
            return ConciergeLookupResult::refused('rate_limited', $this->lockout->foundRetryAfter($property, $address));
        }

        $this->lockout->refund($property, $address, $reference);

        return ConciergeLookupResult::found(basename(($this->issueToken)($stay)), $stay->status === UnitStatus::CheckedIn);
    }
}
