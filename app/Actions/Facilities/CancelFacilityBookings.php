<?php

namespace App\Actions\Facilities;

use App\Enums\FacilityBookingStatus;
use App\Models\Event;
use App\Models\FacilityBooking;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CancelFacilityBookings
{
    /**
     * Cancel every live booking of an event, as `CancelEvent` does. Returns how many were cancelled. Logs one
     * `facility_bookings.cancelled` on the event when there were any. Booking on reservations adds a reservation
     * version. See docs/33-facilities/04-facility-bookings.md#actions.
     */
    public function forEvent(User $actor, Event $event): int
    {
        return DB::transaction(function () use ($actor, $event): int {
            $ids = FacilityBooking::query()
                ->where('event_id', $event->id)
                ->live()
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return 0;
            }

            FacilityBooking::query()->whereKey($ids)->update([
                'status' => FacilityBookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'updated_at' => now(),
            ]);

            activity()
                ->performedOn($event)
                ->causedBy($actor)
                ->event('facility_bookings.cancelled')
                ->withProperties(['property_id' => $event->property_id, 'facility_booking_ids' => $ids])
                ->log('Facility bookings cancelled');

            return count($ids);
        });
    }
}
