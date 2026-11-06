<?php

namespace App\Actions\Payments;

use App\Models\GatewayRefund;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Payments\CancelledUnitRefund;
use Brick\Math\BigDecimal;

class RefundCancelledUnits
{
    public function __construct(
        private CancelledUnitRefund $cancelledUnitRefund,
        private RequestOnlineRefund $requestOnlineRefund,
    ) {}

    /**
     * Ask Curlec to give back what the units just cancelled leave as credit: one refund for each Curlec payment, with one
     * part for each unit, so a group pays one Curlec fee. The caller holds the transaction, so a refusal rolls the cancel
     * back too. With no Curlec money nothing is written. The number in the key counts earlier cancels of this reservation,
     * because a reinstated unit can be cancelled again. A null actor is the guest.
     * See docs/29-booking-engine/05-cancel-and-refund.md#cases.
     *
     * @param  list<int>  $unitIds
     */
    public function __invoke(?User $actor, Reservation $reservation, array $unitIds, ?string $reason = null): CancelledUnitsRefundResult
    {
        $units = ReservationUnit::query()->where('reservation_id', $reservation->id)->whereIn('id', $unitIds)->orderBy('id')->get();
        $byPayment = [];

        foreach ($units as $unit) {
            foreach ($this->cancelledUnitRefund->for($unit)->parts as $part) {
                $byPayment[$part['gatewayPayment']->id][] = $part;
            }
        }

        if ($byPayment === []) {
            return CancelledUnitsRefundResult::requested('0.00');
        }

        $prefix = 'cancel:'.$reservation->id.':';
        $attempt = GatewayRefund::query()->where('property_id', $reservation->property_id)->where('idempotency_key', 'like', $prefix.'%')->count() + 1;
        $total = BigDecimal::zero();

        foreach ($byPayment as $gatewayPaymentId => $parts) {
            $amount = array_reduce($parts, fn (BigDecimal $sum, array $part): BigDecimal => $sum->plus($part['amount']), BigDecimal::zero());
            $result = ($this->requestOnlineRefund)(
                $actor,
                $parts[0]['gatewayPayment'],
                array_map(fn (array $part): array => ['payment' => $part['payment'], 'amount' => $part['amount']], $parts),
                (string) $amount->toScale(2),
                $prefix.$attempt.':'.$gatewayPaymentId,
                $reason,
            );

            if ($result->isRefused()) {
                return CancelledUnitsRefundResult::refused((string) $result->refusal);
            }

            $total = $total->plus($amount);
        }

        return CancelledUnitsRefundResult::requested((string) $total->toScale(2));
    }
}
