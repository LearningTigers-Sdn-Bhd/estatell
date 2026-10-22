<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use App\Models\User;

class VoidPayment
{
    public function __construct(private RefundPayment $refundPayment) {}

    /**
     * Fix a payment recorded by mistake: refund all of it, to its own method, when it was posted on the current
     * business date and has no refund yet. `RefundPayment` runs the checks under the folio lock and logs
     * `payment.voided`. The surcharge stays, as in any refund. See docs/14-payments/01-payments-and-refunds.md.
     */
    public function __invoke(User $actor, Payment $payment, string $idempotencyKey): PaymentResult
    {
        return ($this->refundPayment)($actor, $payment, $payment->amount, null, $idempotencyKey, void: true);
    }
}
