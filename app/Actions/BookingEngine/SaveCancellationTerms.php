<?php

namespace App\Actions\BookingEngine;

use App\Enums\SystemTransactionCode;
use App\Models\Reservation;
use App\Models\ReservationCancellationTerm;
use App\Models\RoomRevenuePolicy;

/**
 * Copy the property's `CANCEL` policy onto a new online booking, so a later change to the policy does not change what the
 * guest was promised. With no policy nothing is saved, and the live policy applies. `ConfirmBookingHold` runs this in its
 * transaction. See docs/29-booking-engine/05-cancel-and-refund.md#saved-terms.
 */
class SaveCancellationTerms
{
    public function __invoke(Reservation $reservation): void
    {
        $policy = RoomRevenuePolicy::query()
            ->where('property_id', $reservation->property_id)
            ->where('code', SystemTransactionCode::CancellationRevenue)
            ->first();

        if ($policy === null) {
            return;
        }

        ReservationCancellationTerm::query()->create([
            'property_id' => $reservation->property_id,
            'reservation_id' => $reservation->id,
            'charge_mode' => $policy->charge_mode,
            'amount' => $policy->amount,
            'percent' => $policy->percent,
            'percent_basis' => $policy->percent_basis,
            'applies_to' => $policy->applies_to,
            'free_until_days' => $policy->free_until_days,
            'text_en' => $policy->text_en,
            'text_ms' => $policy->text_ms,
        ]);
    }
}
