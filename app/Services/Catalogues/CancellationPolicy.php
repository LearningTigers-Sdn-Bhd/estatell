<?php

namespace App\Services\Catalogues;

use App\Enums\SystemTransactionCode;
use App\Models\ReservationCancellationTerm;
use App\Models\RoomRevenuePolicy;

/**
 * The cancellation policy that applies to a reservation: the terms it saved when an online guest booked, otherwise the
 * property's live `CANCEL` policy. Saved terms come back as an unsaved `RoomRevenuePolicy`, so `PolicyChargeCalculator`
 * and `PostUnitPolicyFee` read them like any other policy. See docs/29-booking-engine/05-cancel-and-refund.md#saved-terms.
 */
class CancellationPolicy
{
    public function forReservation(int $propertyId, int $reservationId): ?RoomRevenuePolicy
    {
        $terms = ReservationCancellationTerm::query()->where('property_id', $propertyId)->where('reservation_id', $reservationId)->first();

        if ($terms === null) {
            return RoomRevenuePolicy::query()->where('property_id', $propertyId)->where('code', SystemTransactionCode::CancellationRevenue)->first();
        }

        return (new RoomRevenuePolicy)->forceFill([
            'property_id' => $propertyId,
            'code' => SystemTransactionCode::CancellationRevenue,
            'charge_mode' => $terms->charge_mode,
            'amount' => $terms->amount,
            'percent' => $terms->percent,
            'percent_basis' => $terms->percent_basis,
            'applies_to' => $terms->applies_to,
            'free_until_days' => $terms->free_until_days,
            'text_en' => $terms->text_en,
            'text_ms' => $terms->text_ms,
        ]);
    }
}
