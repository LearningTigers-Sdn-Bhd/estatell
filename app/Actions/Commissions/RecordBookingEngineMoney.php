<?php

namespace App\Actions\Commissions;

use App\Data\Commissions\CommissionEntryInputData;
use App\Enums\CommissionEntryType;
use App\Models\Reservation;
use App\Services\BookingEngine\OnlineBookingMoney;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Writes the money of an ended booking engine reservation into the ledger: what Estatell collected, each processed refund,
 * and each refund's cost to the property (Curlec's refund fee, plus the fee Curlec kept on the refund that gives the whole
 * payment back). The keys make each one post once, so a refund processed after the booking ended is added on its own
 * later run with that run's due date. See docs/30-commission-fees/03-ledger.md#entry-types.
 */
class RecordBookingEngineMoney
{
    public function __construct(
        private OnlineBookingMoney $onlineBookingMoney,
        private PostCommissionEntry $postCommissionEntry,
    ) {}

    public function __invoke(Reservation $reservation, CarbonImmutable $dueDate): void
    {
        $money = $this->onlineBookingMoney->forReservation($reservation);

        if ($money === null) {
            return;
        }

        $property = $reservation->property;
        $post = fn (CommissionEntryInputData $data) => ($this->postCommissionEntry)($property, $data);

        $post(new CommissionEntryInputData(
            type: CommissionEntryType::Collected,
            amount: $money->amount,
            dueDate: $dueDate,
            reservationId: $reservation->id,
            gatewayPaymentId: $money->gatewayPaymentId,
            idempotencyKey: 'collected:'.$money->gatewayPaymentId,
        ));

        $refunded = BigDecimal::zero();
        $refundFee = BigDecimal::of((string) config('services.curlec.refund_fee'));

        foreach ($money->refunds as $refund) {
            $refunded = $refunded->plus($refund->amount);
            $isWholePayment = $refunded->isGreaterThanOrEqualTo($money->amount);
            $cost = $isWholePayment && $money->curlecFee !== null ? $refundFee->plus($money->curlecFee) : $refundFee;

            $post(new CommissionEntryInputData(
                type: CommissionEntryType::Refunded,
                amount: (string) BigDecimal::of($refund->amount)->negated(),
                dueDate: $dueDate,
                reservationId: $reservation->id,
                gatewayRefundId: $refund->gatewayRefundId,
                idempotencyKey: 'refunded:'.$refund->gatewayRefundId,
            ));

            if ($cost->isPositive()) {
                $post(new CommissionEntryInputData(
                    type: CommissionEntryType::RefundFee,
                    amount: (string) $cost->negated()->toScale(2),
                    dueDate: $dueDate,
                    reservationId: $reservation->id,
                    gatewayRefundId: $refund->gatewayRefundId,
                    idempotencyKey: 'refund-fee:'.$refund->gatewayRefundId,
                ));
            }
        }
    }
}
