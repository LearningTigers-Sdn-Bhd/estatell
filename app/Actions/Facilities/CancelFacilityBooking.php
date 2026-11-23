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
    /**
     * Cancel one booking. A Cancelled booking is refused (false), and nothing is written or logged. Logs
     * `facility_bookings.cancelled` on its event or reservation. Reversing a posted charge comes with the night audit
     * item. See docs/33-facilities/04-facility-bookings.md#actions.
     */
    public function __invoke(User $actor, FacilityBooking $booking): bool
    {
        return DB::transaction(function () use ($actor, $booking): bool {
            $current = FacilityBooking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! $current->status->canTransitionTo(FacilityBookingStatus::Cancelled)) {
                return false;
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

            return true;
        });
    }
}
