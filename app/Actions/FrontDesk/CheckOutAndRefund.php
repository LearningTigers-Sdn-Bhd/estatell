<?php

namespace App\Actions\FrontDesk;

use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundPayment;
use App\Data\Payments\NewPaymentData;
use App\Data\Reservations\CheckOutCollectData;
use App\Enums\PaymentPurpose;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\FrontDesk\CheckOutSettlement;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckOutAndRefund
{
    public function __construct(
        private CheckOutUnit $checkOutUnit,
        private RecordPayment $recordPayment,
        private RefundPayment $refundPayment,
        private CheckOutSettlement $checkOutSettlement,
    ) {}

    /**
     * The check-out form: collect the balance, give the security deposit back, then check the unit out with
     * `CheckOutUnit`, in one transaction. `$collect` records a Settlement payment on the unit folio with
     * `RecordPayment`, for the amount the form showed. `$refundAmount` is the part of the deposit to give back. It is
     * taken from the deposit payments oldest first, each through `RefundPayment` (which refuses more than a payment
     * holds), and an amount above what is held is refused with `refund_exceeds_payment` before anything is written.
     * A refusal of the payment, the refund or the check-out (for example `balance_not_settled` when the amount no
     * longer settles the folio) comes back as the refusal code and rolls every step back. `$idempotencyKey` is a UUID
     * from the form: the payment uses `{uuid}:collect` and each refund `{uuid}:{payment id}`. Permissions are checked
     * by the route and the Form Request. See docs/18-front-desk/01-check-in-and-check-out.md#check-out.
     */
    public function __invoke(User $actor, ReservationUnit $unit, ?string $refundAmount = null, ?CheckOutCollectData $collect = null, ?string $idempotencyKey = null): UnitStayResult
    {
        DB::beginTransaction();

        try {
            $result = $this->run($actor, $unit, $refundAmount, $collect, $idempotencyKey);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $result->isRefused() ? DB::rollBack() : DB::commit();

        return $result;
    }

    private function run(User $actor, ReservationUnit $unit, ?string $refundAmount, ?CheckOutCollectData $collect, ?string $idempotencyKey): UnitStayResult
    {
        if ($collect !== null) {
            $payment = ($this->recordPayment)($actor, $unit->reservation, null, $unit, new NewPaymentData(
                $collect->paymentMethodId,
                PaymentPurpose::Settlement,
                $collect->amount,
                $collect->reference,
                $collect->idempotencyKey.':collect',
            ));

            if ($payment->isRefused()) {
                return UnitStayResult::refused((string) $payment->refusal);
            }
        }

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
                $result = ($this->refundPayment)($actor, $payment, (string) $part->toScale(2), null, ($idempotencyKey ?? '').':'.$payment->id);

                if ($result->isRefused()) {
                    return UnitStayResult::refused((string) $result->refusal);
                }

                $left = $left->minus($part);
            }
        }

        return ($this->checkOutUnit)($actor, $unit);
    }
}
