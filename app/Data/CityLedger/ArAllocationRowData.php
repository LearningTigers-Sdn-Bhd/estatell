<?php

namespace App\Data\CityLedger;

use App\Models\ArAllocation;
use Spatie\LaravelData\Data;

/**
 * One allocation on the invoice page. `canReverse` includes the permission and the state.
 */
class ArAllocationRowData extends Data
{
    public function __construct(
        public int $id,
        public int $paymentId,
        public int $companyId,
        public string $receiptNumber,
        public string $receivedOn,
        public string $amount,
        public bool $isReversed,
        public ?string $reversalReason,
        public bool $canReverse,
    ) {}

    /**
     * Expects `payment` to be loaded.
     */
    public static function fromModel(ArAllocation $allocation, bool $mayReverse): self
    {
        $isReversed = $allocation->reversed_at !== null;

        return new self(
            id: $allocation->id,
            paymentId: $allocation->ar_payment_id,
            companyId: $allocation->payment->company_id,
            receiptNumber: $allocation->payment->receipt_number,
            receivedOn: $allocation->payment->received_on->toDateString(),
            amount: $allocation->amount,
            isReversed: $isReversed,
            reversalReason: $allocation->reversal_reason,
            canReverse: $mayReverse && ! $isReversed,
        );
    }
}
