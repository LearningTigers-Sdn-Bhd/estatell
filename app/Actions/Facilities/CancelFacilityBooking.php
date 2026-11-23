<?php

namespace App\Actions\Facilities;

use App\Enums\FacilityBookingStatus;
use App\Models\Event;
use App\Models\FacilityBooking;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CancelFacilityBooking
{
    public function __construct(private ReverseFacilityBookingCharge $reverseCharge) {}

    /**
     * Cancel one booking. A posted booking has its charge reversed first (`ReverseFacilityBookingCharge`). Refuses a
     * Cancelled booking (`facility_booking_cancelled`) and a posted one whose folio is not Open (`folio_not_open`); a
     * refusal writes and logs nothing. Logs `facility_bookings.cancelled` on its event or reservation.
     * See docs/33-facilities/04-facility-bookings.md#actions.
     */
    public function __invoke(User $actor, FacilityBooking $booking): FacilityBookingResult
    {
        return DB::transaction(function () use ($actor, $booking): FacilityBookingResult {
            $current = FacilityBooking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! $current->status->canTransitionTo(FacilityBookingStatus::Cancelled)) {
                return FacilityBookingResult::refused(FacilityBookingResult::REFUSAL_BOOKING_CANCELLED);
            }

            $refusal = ($this->reverseCharge)($actor, $current);

            if ($refusal !== null) {
                return FacilityBookingResult::refused($refusal);
            }

            $current->forceFill([
                'status' => FacilityBookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ])->save();

            activity()
                ->performedOn($current->event_id !== null
                    ? Event::query()->findOrFail($current->event_id)
                    : Reservation::query()->findOrFail($current->reservation_id))
                ->causedBy($actor)
                ->event('facility_bookings.cancelled')
                ->withProperties(['property_id' => $current->property_id, 'facility_booking_ids' => [$current->id]])
                ->log('Facility booking cancelled');

            return FacilityBookingResult::done($current);
        });
    }
}
