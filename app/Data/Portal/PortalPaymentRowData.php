<?php

namespace App\Data\Portal;

use App\Models\ArPayment;
use Spatie\LaravelData\Data;

/**
 * One payment the company made at the property, on the portal statements page. Expects `paymentMethod` to be loaded.
 */
class PortalPaymentRowData extends Data
{
    public function __construct(
        public int $id,
        public string $receiptNumber,
        public bool $hasReceipt,
        public string $receivedOn,
        public string $methodName,
        public ?string $reference,
        public string $amount,
    ) {}

    public static function fromModel(ArPayment $payment): self
    {
        return new self(
            id: $payment->id,
            receiptNumber: $payment->displayNumber(),
            hasReceipt: $payment->hasReceipt(),
            receivedOn: $payment->received_on->toDateString(),
            methodName: $payment->paymentMethod->name,
            reference: $payment->reference,
            amount: $payment->amount,
        );
    }
}
