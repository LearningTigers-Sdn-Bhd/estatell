<?php

namespace App\Data\CityLedger;

use App\Models\ArPayment;
use Spatie\LaravelData\Data;

/**
 * One AR payment of the Payments tab. `canAllocate` and `canVoid` already include the permission and the state, so the
 * page never works them out.
 */
class ArPaymentRowData extends Data
{
    public function __construct(
        public int $id,
        public string $receiptNumber,
        public string $receivedOn,
        public string $methodName,
        public ?string $reference,
        public ?string $notes,
        public string $amount,
        public string $unallocated,
        public bool $isVoided,
        public bool $canAllocate,
        public bool $canVoid,
    ) {}

    /**
     * Expects `paymentMethod` to be loaded. `$unallocated` comes from the list query and `$hasAllocations` says
     * whether the payment has an active allocation.
     */
    public static function fromModel(ArPayment $payment, string $unallocated, bool $hasAllocations, bool $mayAllocate, bool $mayVoid): self
    {
        $isVoided = $payment->voided_at !== null;

        return new self(
            id: $payment->id,
            receiptNumber: $payment->receipt_number,
            receivedOn: $payment->received_on->toDateString(),
            methodName: $payment->paymentMethod->name,
            reference: $payment->reference,
            notes: $payment->notes,
            amount: $payment->amount,
            unallocated: $unallocated,
            isVoided: $isVoided,
            canAllocate: $mayAllocate && ! $isVoided && bccomp($unallocated, '0', 2) > 0,
            canVoid: $mayVoid && ! $isVoided && ! $hasAllocations,
        );
    }
}
