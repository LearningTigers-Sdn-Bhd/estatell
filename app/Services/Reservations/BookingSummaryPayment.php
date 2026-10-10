<?php

namespace App\Services\Reservations;

use Carbon\CarbonImmutable;

/**
 * One payment row of the booking summary. `amount` is the ledger amount: negative for a payment (printed in
 * brackets), positive for a refund. `text` is the method, "Refund · {method}", or "Billed to {company}, invoice {number}".
 */
final readonly class BookingSummaryPayment
{
    public function __construct(
        public CarbonImmutable $date,
        public ?string $receiptNumber,
        public string $text,
        public string $amount,
    ) {}
}
