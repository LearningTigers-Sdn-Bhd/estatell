<?php

namespace App\Data\CityLedger;

use Spatie\LaravelData\Data;

/**
 * One payment a company made directly on a booking (a folio it pays), on its statement. It is not a city ledger entry,
 * so it does not change the statement's balance. A refund has a negative amount.
 */
class StatementBookingPaymentData extends Data
{
    public function __construct(
        public string $date,
        public string $booking,
        public string $method,
        public ?string $reference,
        public ?string $receipt,
        public string $amount,
    ) {}
}
