<?php

namespace App\Data\Concierge;

use App\Data\Property\PropertySummaryData;
use App\Enums\ConciergeStep;
use Spatie\LaravelData\Data;

/**
 * Everything the Concierge page shows for one stay. `roomNumber` and `pin` are set only while the key is shown;
 * `shortfall` only on the payment step; `terms` only on the card step; `documents` once the guest is checked in or out; `canRequestRefund` and `refundRequest` only once
 * the guest has checked out. `checkOutAsked` is true while the guest's request for the front desk to check them out is open. Only the guest's first name is sent, and never the room outside the key step. `reference` is the booking code the guest already has. `identity` holds the details already saved for the guest, on the ID step only.
 */
class ConciergeStayData extends Data
{
    /**
     * @param  list<ConciergeDocumentData>  $documents
     */
    public function __construct(
        public string $token,
        public ConciergeStep $step,
        public PropertySummaryData $property,
        public string $guestFirstName,
        public string $arrivalDate,
        public string $departureDate,
        public string $reference,
        public int $nights,
        public bool $nationalityRequired,
        public ?string $roomNumber,
        public ?string $shortfall,
        public ?ConciergeTermsData $terms,
        public ?ConciergePinData $pin,
        public array $documents = [],
        public bool $canRequestRefund = false,
        public bool $checkOutAsked = false,
        public ?ConciergeRequestData $refundRequest = null,
        public bool $locationRequired = false,
        public ?ConciergeIdentityPrefillData $identity = null,
    ) {}
}
