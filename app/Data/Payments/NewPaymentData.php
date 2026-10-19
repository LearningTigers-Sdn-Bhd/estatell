<?php

namespace App\Data\Payments;

use App\Enums\PaymentPurpose;
use Spatie\LaravelData\Data;

/**
 * One payment to record. `amount` is the amount paid on the bill as a decimal string; any surcharge and its tax are
 * added on top. `idempotencyKey` is a UUID sent by the form: the payment line key is `payment:{uuid}`.
 * The manual folio page also gives `serviceDate` (a `Y-m-d` day in `ServiceDateRange`) and `manualBatchId`. An Old system
 * payment must give `serviceDate`, the day the old PMS took the money (any real day up to the business date).
 * See docs/14-payments/01-payments-and-refunds.md.
 */
class NewPaymentData extends Data
{
    public function __construct(
        public int $paymentMethodId,
        public PaymentPurpose $purpose,
        public string $amount,
        public ?string $reference,
        public string $idempotencyKey,
        public ?string $serviceDate = null,
        public ?int $manualBatchId = null,
    ) {}
}
