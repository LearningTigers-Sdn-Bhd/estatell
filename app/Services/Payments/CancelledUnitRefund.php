<?php

namespace App\Services\Payments;

use App\Enums\FolioStatus;
use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentMethodType;
use App\Models\Folio;
use App\Models\GatewayPayment;
use App\Models\Payment;
use App\Models\ReservationUnit;
use App\Services\FrontDesk\CheckOutSettlement;
use Brick\Math\BigDecimal;

/**
 * What a cancelled unit gives back through Curlec: the credit on its Open folios, up to what its Curlec payments still
 * hold. A preview passes the fee with tax as `$stillToPost`, and the refund after the cancel passes nothing, because the
 * fee is then on the folio. Any other payment method stays as credit for staff to refund. An Online payment with no Curlec
 * row, such as one migrated from another system, stays too. See docs/29-booking-engine/05-cancel-and-refund.md#cases.
 */
class CancelledUnitRefund
{
    public function __construct(private CheckOutSettlement $settlement) {}

    public function for(ReservationUnit $unit, ?BigDecimal $stillToPost = null): CancelledUnitShare
    {
        $folios = $this->settlement->folios($unit);
        $credit = $folios
            ->filter(fn (Folio $folio): bool => $folio->status === FolioStatus::Open)
            ->reduce(function (BigDecimal $sum, Folio $folio): BigDecimal {
                $balance = $this->settlement->settledBalance($folio);

                return $balance->isNegative() ? $sum->minus($balance) : $sum;
            }, BigDecimal::zero());
        $left = $credit->minus($stillToPost ?? BigDecimal::zero());
        $isShort = $left->isNegative();

        $payments = $this->settlement->heldPayments($folios)
            ->filter(fn (Payment $payment): bool => $payment->paymentMethod->type === PaymentMethodType::Online && $payment->reference !== null)
            ->values();
        $gatewayPayments = GatewayPayment::query()
            ->where('property_id', $unit->property_id)
            ->where('status', GatewayPaymentStatus::Paid)
            ->whereIn('gateway_payment_id', $payments->pluck('reference'))
            ->get()
            ->keyBy('gateway_payment_id');

        $held = BigDecimal::zero();
        $parts = [];

        foreach ($payments as $payment) {
            $gatewayPayment = $gatewayPayments->get($payment->reference);

            if ($gatewayPayment === null) {
                continue;
            }

            $available = $this->settlement->refundable($payment);
            $held = $held->plus($available);

            if (! $left->isPositive()) {
                continue;
            }

            $share = $left->isLessThan($available) ? $left : $available;
            $parts[] = ['payment' => $payment, 'gatewayPayment' => $gatewayPayment, 'amount' => (string) $share->toScale(2)];
            $left = $left->minus($share);
        }

        return new CancelledUnitShare($held, $isShort, $parts);
    }
}
