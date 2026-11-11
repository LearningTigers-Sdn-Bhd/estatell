<?php

namespace App\Data\CityLedger;

use App\Models\ArPayment;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * One AR payment of the Payments tab. `canAllocate` and `canVoid` already include the permission and the state, so the
 * page never works them out. `slipUrl` opens the payment slip it was accepted from, null for a payment recorded by hand.
 */
class ArPaymentRowData extends Data
{
    public function __construct(
        public int $id,
        public string $receiptNumber,
        public bool $hasReceipt,
        public string $receivedOn,
        public string $methodName,
        public ?string $reference,
        public ?string $notes,
        public string $amount,
        public string $unallocated,
        public bool $isVoided,
        public bool $canAllocate,
        public bool $canVoid,
        public ?string $slipUrl = null,
    ) {}

    /**
     * Expects `paymentMethod` to be loaded. `$unallocated` comes from the list query and `$hasAllocations` says
     * whether the payment has an active allocation.
     */
    public static function fromModel(ArPayment $payment, string $unallocated, bool $hasAllocations, bool $mayAllocate, bool $mayVoid, ?string $slipUrl = null): self
    {
        $isVoided = $payment->voided_at !== null;

        return new self(
            id: $payment->id,
            receiptNumber: $payment->displayNumber(),
            hasReceipt: $payment->hasReceipt(),
            receivedOn: $payment->received_on->toDateString(),
            methodName: $payment->paymentMethod->name,
            reference: $payment->reference,
            notes: $payment->notes,
            amount: $payment->amount,
            unallocated: $unallocated,
            isVoided: $isVoided,
            canAllocate: $mayAllocate && ! $isVoided && BigDecimal::of($unallocated)->isPositive(),
            canVoid: $mayVoid && ! $isVoided && ! $hasAllocations,
            slipUrl: $slipUrl,
        );
    }
}
