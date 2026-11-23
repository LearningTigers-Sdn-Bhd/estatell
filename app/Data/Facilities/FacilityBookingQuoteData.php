<?php

namespace App\Data\Facilities;

use Spatie\LaravelData\Data;

/**
 * The server's answer for the Book facility Sheet: the price line, the refusal the booking would get, the Tentative
 * hold warning and, for a facility with sessions, the places left in each session. `amount` is the price
 * before tax (the unit price times the rate basis quantity); `amount`, `taxes` and `total` are empty when the
 * facility is charged by hand or the times are not chosen yet. `breakdown` says how `amount` adds up without the price
 * ("3 items × 2 hours"). See docs/33-facilities/04-facility-bookings.md.
 */
class FacilityBookingQuoteData extends Data
{
    /**
     * @param  list<FacilityQuoteTaxData>  $taxes
     * @param  list<FacilitySessionPlacesData>  $sessions
     */
    public function __construct(
        public ?string $amount,
        public array $taxes,
        public ?string $total,
        public ?string $refusal,
        public ?string $warning,
        public array $sessions,
        public ?string $breakdown = null,
    ) {}
}
