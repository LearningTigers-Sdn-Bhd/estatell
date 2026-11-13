<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * What the Record payment Dialog of a Master or third-party folio needs to warn that a payment becomes a credit while
 * other folios of the booking still owe: what this folio `owes` (its projected total, never below 0), how many other
 * folios owe and their total, and the name of the other folio when it is only one. All figures count Scheduled rows.
 * See docs/14-payments/01-payments-and-refunds.md#credit-warning.
 */
class PaymentCreditWarningData extends Data
{
    public function __construct(
        public string $owes,
        public int $othersCount,
        public string $othersTotal,
        public ?string $othersLabel = null,
    ) {}
}
