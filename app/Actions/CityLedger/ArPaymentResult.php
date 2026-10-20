<?php

namespace App\Actions\CityLedger;

use App\Models\ArAllocation;
use App\Models\ArPayment;

/**
 * What the AR payment Actions return: the payment and the allocations the call made, or a refusal code. A refusal
 * writes and logs nothing. `replayed` is true when a repeated key returned the existing payment.
 */
class ArPaymentResult
{
    public const REFUSAL_METHOD_NOT_AVAILABLE = 'method_not_available';

    public const REFUSAL_AMOUNT_INVALID = 'amount_invalid';

    public const REFUSAL_REFERENCE_REQUIRED = 'reference_required';

    public const REFUSAL_DATE_IN_FUTURE = 'date_in_future';

    public const REFUSAL_NO_BUSINESS_DATE = 'no_business_date';

    public const REFUSAL_PAYMENT_NOT_ALLOCATABLE = 'payment_not_allocatable';

    public const REFUSAL_INVOICE_NOT_ALLOCATABLE = 'invoice_not_allocatable';

    public const REFUSAL_EXCEEDS_INVOICE_BALANCE = 'exceeds_invoice_balance';

    public const REFUSAL_EXCEEDS_UNALLOCATED = 'exceeds_unallocated';

    public const REFUSAL_ALREADY_REVERSED = 'already_reversed';

    public const REFUSAL_REASON_REQUIRED = 'reason_required';

    public const REFUSAL_HAS_ALLOCATIONS = 'payment_has_allocations';

    public const REFUSAL_ALREADY_VOID = 'already_void';

    public const REFUSAL_MIGRATION_WINDOW_CLOSED = 'migration_window_closed';

    public const REFUSAL_COMPANY_NOT_AVAILABLE = 'company_not_available';

    public const REFUSAL_DATE_NOT_BEFORE_GO_LIVE = 'date_not_before_go_live';

    /**
     * @param  list<ArAllocation>  $allocations
     */
    private function __construct(
        public readonly ?ArPayment $payment,
        public readonly array $allocations,
        public readonly ?string $refusal,
        public readonly bool $replayed = false,
    ) {}

    /**
     * @param  list<ArAllocation>  $allocations
     */
    public static function done(ArPayment $payment, array $allocations = []): self
    {
        return new self($payment, $allocations, null);
    }

    public static function replayed(ArPayment $payment): self
    {
        return new self($payment, [], null, true);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, [], $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
