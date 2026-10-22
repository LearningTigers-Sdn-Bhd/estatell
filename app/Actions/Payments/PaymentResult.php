<?php

namespace App\Actions\Payments;

use App\Models\FolioLine;
use App\Models\Payment;

/**
 * What `RecordPayment` and `RefundPayment` return: the payment (or refund) row and the folio lines that were written,
 * or a refusal code. `lines` holds the surcharge line and its tax lines first, then the payment line. A repeated key
 * returns the existing row with `replayed` set, no lines, and writes nothing. The `TaxCalculator` refusal codes pass
 * through as they are.
 */
class PaymentResult
{
    public const REFUSAL_NO_BUSINESS_DATE = 'no_business_date';

    public const REFUSAL_FOLIO_NOT_OPEN = 'folio_not_open';

    public const REFUSAL_DEPOSIT_ON_UNIT_ONLY = 'deposit_on_unit_only';

    public const REFUSAL_ADVANCE_ON_MASTER = 'advance_on_master';

    public const REFUSAL_PURPOSE_NOT_OFFERED = 'purpose_not_offered';

    public const REFUSAL_METHOD_NOT_AVAILABLE = 'method_not_available';

    public const REFUSAL_AMOUNT_INVALID = 'amount_invalid';

    public const REFUSAL_REFERENCE_REQUIRED = 'reference_required';

    public const REFUSAL_PAYMENT_NOT_FOUND = 'payment_not_found';

    public const REFUSAL_NOT_REFUNDABLE = 'not_refundable';

    public const REFUSAL_REFUND_EXCEEDS_PAYMENT = 'refund_exceeds_payment';

    public const REFUSAL_SERVICE_DATE_INVALID = 'service_date_invalid';

    public const REFUSAL_REFUND_METHOD_MISMATCH = 'refund_method_mismatch';

    public const REFUSAL_OTHER_METHOD_NOT_ALLOWED = 'other_method_not_allowed';

    public const REFUSAL_REASON_REQUIRED = 'reason_required';

    public const REFUSAL_VOID_NOT_SAME_DAY = 'void_not_same_day';

    public const REFUSAL_VOID_HAS_REFUND = 'void_has_refund';

    public const REFUSAL_CREDIT_NOT_REFUNDABLE = 'credit_not_refundable';

    /**
     * @param  list<FolioLine>  $lines
     */
    private function __construct(
        public readonly ?Payment $payment,
        public readonly array $lines,
        public readonly bool $replayed,
        public readonly ?string $refusal,
    ) {}

    /**
     * @param  list<FolioLine>  $lines
     */
    public static function recorded(Payment $payment, array $lines): self
    {
        return new self($payment, $lines, false, null);
    }

    public static function replayed(Payment $payment): self
    {
        return new self($payment, [], true, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, [], false, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
