<?php

namespace App\Services\Payments;

/**
 * Everything the receipt PDF shows, already formatted, so the Blade views do no lookups. Amounts are decimal strings
 * with two places. `surcharge` and `surchargeTax` are null when the payment had no fee.
 */
class ReceiptDocument
{
    public function __construct(
        public readonly string $receiptNumber,
        public readonly string $postingDate,
        public readonly string $receivedAt,
        public readonly string $payerName,
        public readonly string $reservationReference,
        public readonly string $folioNumber,
        public readonly string $method,
        public readonly ?string $reference,
        public readonly string $purpose,
        public readonly string $currency,
        public readonly string $amountPaid,
        public readonly ?string $surcharge,
        public readonly ?string $surchargeTax,
        public readonly string $total,
        public readonly string $receivedBy,
    ) {}
}
