<?php

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;

/**
 * A company invoice to create: one line that posts to `accountCode` (the city ledger clearing account), with no tax,
 * because revenue and tax are already in the daily journal. `reference` is our invoice number.
 */
final readonly class InvoiceDetails
{
    public function __construct(
        public string $customerId,
        public string $reference,
        public CarbonImmutable $date,
        public CarbonImmutable $dueDate,
        public string $currency,
        public string $description,
        public string $amount,
        public string $accountCode,
    ) {}
}
