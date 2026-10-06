<?php

namespace App\Actions\FrontDesk;

use App\Actions\Payments\RefundPayment;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\FrontDesk\CheckOutSettlement;
use Brick\Math\BigDecimal;

class CheckOutAndRefund
{
    public function __construct(
        private CheckOutUnit $checkOutUnit,
        private RefundPayment $refundPayment,
        private CheckOutSettlement $checkOutSettlement,
    ) {}

    /**
     * The check-out form: give the security deposit back, then check the unit out with `CheckOutUnit`. A held deposit
     * is a credit, so the unit folio reaches 0 only after the refund. `$refundAmount` is the part of the deposit to
     * give back. It is taken from the deposit payments oldest first, each through `RefundPayment` (which refuses more
     * than a payment holds), and an amount above what is held is refused with `refund_exceeds_payment` before
     * anything is written. A refusal of the refund or of the check-out comes back as the refusal code. A refund that
     * succeeded stays when the check-out is refused afterwards (for example `balance_not_settled`), because it is
     * posted money. `$idempotencyKey` is a UUID from the form and each refund uses `{uuid}:{payment id}`.
     * Permissions are checked by the route and the Form Request. See docs/18-front-desk/01-check-in-and-check-out.md#check-out.
     */
    public function __invoke(User $actor, ReservationUnit $unit, ?string $refundAmount = null, ?string $reference = null, ?string $idempotencyKey = null): UnitStayResult
    {
        if ($refundAmount !== null && bccomp($refundAmount, '0', 2) > 0) {
            $left = BigDecimal::of($refundAmount);
            $deposits = $this->checkOutSettlement->heldDeposits($this->checkOutSettlement->folios($unit));
            $held = $deposits->reduce(fn (BigDecimal $sum, $payment): BigDecimal => $sum->plus($this->checkOutSettlement->refundable($payment)), BigDecimal::zero());

            if ($left->isGreaterThan($held)) {
                return UnitStayResult::refused('refund_exceeds_payment');
            }

            foreach ($deposits as $payment) {
                if ($left->isZero()) {
                    break;
                }

                $part = $left->isLessThan($this->checkOutSettlement->refundable($payment)) ? $left : $this->checkOutSettlement->refundable($payment);
                $result = ($this->refundPayment)($actor, $payment, (string) $part->toScale(2), $reference, ($idempotencyKey ?? '').':'.$payment->id);

                if ($result->isRefused()) {
                    return UnitStayResult::refused((string) $result->refusal);
                }

                $left = $left->minus($part);
            }
        }

        return ($this->checkOutUnit)($actor, $unit);
    }
}
