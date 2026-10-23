<?php

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;

/**
 * A company payment to create: `amount` is the total matched to invoices, banked to `accountCode`. `reference` is our receipt
 * number.
 */
final readonly class ReceiptDetails
{
    /**
     * @param  list<ReceiptLink>  $links
     */
    public function __construct(
        public string $customerId,
        public string $reference,
        public CarbonImmutable $date,
        public string $currency,
        public string $description,
        public string $amount,
        public string $accountCode,
        public array $links,
    ) {}
}
