<?php

namespace App\Services\BookingEngine;

use App\Enums\GatewayPaymentStatus;
use App\Enums\GatewayRefundStatus;
use App\Models\BookingHold;
use App\Models\GatewayPayment;
use App\Models\GatewayRefund;
use App\Models\Reservation;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The money Estatell collected through Curlec for a booking engine reservation: the paid payment, the fee Curlec charged,
 * the room total the guest booked before tax, and the processed refunds. Commission fees reads it.
 * See docs/30-commission-fees/03-ledger.md.
 */
class OnlineBookingMoney
{
    public function forReservation(Reservation $reservation): ?OnlineMoney
    {
        $hold = BookingHold::query()
            ->with('lines')
            ->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)
            ->first();

        if ($hold === null) {
            return null;
        }

        $payment = GatewayPayment::query()
            ->where('booking_hold_id', $hold->id)
            ->where('status', GatewayPaymentStatus::Paid)
            ->first();

        if ($payment === null) {
            return null;
        }

        $roomTotals = $hold->lines->pluck('room_total');

        return new OnlineMoney(
            gatewayPaymentId: $payment->id,
            amount: $payment->amount,
            curlecFee: $this->curlecFee($payment),
            bookedRoomTotal: $roomTotals->contains(null) ? null : (string) $roomTotals->reduce(
                fn (BigDecimal $sum, string $total): BigDecimal => $sum->plus($total),
                BigDecimal::zero(),
            )->toScale(2),
            refunds: array_values(GatewayRefund::query()
                ->where('gateway_payment_id', $payment->id)
                ->where('status', GatewayRefundStatus::Processed)
                ->orderBy('id')
                ->get()
                ->map(fn (GatewayRefund $refund): OnlineRefund => new OnlineRefund($refund->id, $refund->amount))
                ->all()),
        );
    }

    /**
     * Money Estatell holds for confirmed online bookings whose payment is not in the commission ledger yet (the booking
     * has not ended): paid payments less their processed refunds. A late payment with no booking is left out.
     * See docs/30-commission-fees/03-ledger.md#totals.
     */
    public function heldOutsideLedger(?int $propertyId = null): string
    {
        $payments = GatewayPayment::query()
            ->where('status', GatewayPaymentStatus::Paid)
            ->whereHas('hold', fn (Builder $hold) => $hold->whereNotNull('reservation_id'))
            ->whereNotExists(fn (QueryBuilder $entries) => $entries
                ->from('commission_entries')
                ->whereColumn('commission_entries.gateway_payment_id', 'gateway_payments.id'))
            ->when($propertyId !== null, fn (Builder $query) => $query->where('property_id', $propertyId));

        $paid = BigDecimal::of((string) ($payments->clone()->sum('amount') ?: '0'));
        $refunded = BigDecimal::of((string) (GatewayRefund::query()
            ->where('status', GatewayRefundStatus::Processed)
            ->whereIn('gateway_payment_id', $payments->clone()->select('id'))
            ->sum('amount') ?: '0'));

        return (string) $paid->minus($refunded)->toScale(2);
    }

    /**
     * Curlec's fee on the payment, tax included. Curlec sends it in sen on the payment entity; null when it did not.
     */
    private function curlecFee(GatewayPayment $payment): ?string
    {
        $fee = data_get($payment->gateway_payload, 'fee');

        return is_int($fee) ? (string) BigDecimal::of($fee)->dividedBy(100, 2) : null;
    }
}
