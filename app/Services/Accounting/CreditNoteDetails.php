<?php

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;

/**
 * A credit note to create against an invoice already sent. `amount` is positive.
 */
final readonly class CreditNoteDetails
{
    public function __construct(
        public string $customerId,
        public string $reference,
        public CarbonImmutable $date,
        public string $currency,
        public string $description,
        public string $amount,
        public string $accountCode,
        public DocumentRef $invoice,
    ) {}
}
