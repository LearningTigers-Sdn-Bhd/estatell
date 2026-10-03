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

    public const REFUSAL_METHOD_NOT_AVAILABLE = 'method_not_available';

    public const REFUSAL_AMOUNT_INVALID = 'amount_invalid';

    public const REFUSAL_REFERENCE_REQUIRED = 'reference_required';

    public const REFUSAL_PAYMENT_NOT_FOUND = 'payment_not_found';

    public const REFUSAL_NOT_REFUNDABLE = 'not_refundable';

    public const REFUSAL_REFUND_EXCEEDS_PAYMENT = 'refund_exceeds_payment';

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
