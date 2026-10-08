<?php

namespace App\Services\Folio;

use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Reservation;

/**
 * The money held on the active Master folio of a reservation: its Completed payments minus their refunds, as a
 * decimal string with two places. A payment line never moves, so a billing mode or company change that would leave
 * this money on a folio that no longer fits is refused. It reads only. See docs/12-reservations/02-group-bookings.md.
 */
class MasterPaymentsHeld
{
    public function forReservation(Reservation $reservation): string
    {
        $payments = Payment::query()
            ->whereHas('folio', fn ($folios) => $folios
                ->where('reservation_id', $reservation->id)
                ->where('type', FolioType::Master)
                ->where('status', '<>', FolioStatus::Voided))
            ->where('status', PaymentStatus::Completed);

        $paid = (string) (clone $payments)->whereNull('refund_of_id')->sum('amount');
        $refunded = (string) (clone $payments)->whereNotNull('refund_of_id')->sum('amount');

        return bcsub(bcadd($paid, '0', 2), bcadd($refunded, '0', 2), 2);
    }

    /**
     * An amount as `MYR 1,000.00`, with the property's currency, for a refusal message.
     */
    public function label(Reservation $reservation, string $amount): string
    {
        $currency = $reservation->property()->with('settings')->first()?->settings?->currency->value;

        return trim($currency.' '.number_format((float) $amount, 2));
    }
}
