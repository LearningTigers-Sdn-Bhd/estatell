<?php

namespace App\Actions\Concierge;

use App\Actions\Kiosk\IssueBookingPreCheckInToken;
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
     * Open a stay from the lobby page by booking code alone. Booking codes run in order, so the lockout is the only guard against guessing. Every lookup takes an attempt from the lockout, and a
     * stay that is found gives it back. The link it returns is the stay's own pre-check-in link, so every later page
     * works from one signed token and no session. Refusals are `rate_limited` and `not_found`; nothing is written for either.
     */
    public function __invoke(Property $property, string $reference, string $address): ConciergeLookupResult
    {
        if (! $this->lockout->attempt($property, $address, $reference)) {
            return ConciergeLookupResult::refused('rate_limited', $this->lockout->retryAfter($property, $address, $reference));
        }

        $stay = $this->stays->byReference($property, $reference);

        if ($stay === null) {
            return ConciergeLookupResult::refused('not_found');
        }

        if (! $this->lockout->found($property, $address)) {
            return ConciergeLookupResult::refused('rate_limited', $this->lockout->foundRetryAfter($property, $address));
        }

        $this->lockout->refund($property, $address, $reference);

        return ConciergeLookupResult::found(basename(($this->issueToken)($stay)));
    }
}
