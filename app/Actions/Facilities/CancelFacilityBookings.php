<?php

namespace App\Actions\Facilities;

use App\Enums\FacilityBookingStatus;
use App\Enums\UnitStatus;
use App\Models\Event;
use App\Models\FacilityBooking;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CancelFacilityBookings
{
    public function __construct(private ReverseFacilityBookingCharge $reverseCharge) {}

    /**
     * Cancel every live booking of an event, as `CancelEvent` does. A posted booking has its charge reversed when its
     * folio is Open; on a closed folio the charge stays, for staff to correct, and the cancel still goes ahead so the
     * event or unit change is never blocked. Returns how many were cancelled. Logs one `facility_bookings.cancelled` on
     * the event when there were any. See docs/33-facilities/04-facility-bookings.md#actions.
     */
    public function forEvent(User $actor, Event $event): int
    {
        return $this->cancel($actor, $event, 'event_id');
    }

    /**
     * Cancel every live booking of a reservation once it has no live unit left (every unit Cancelled or No-show).
     * `CancelUnit` and `MarkNoShow` call it after they change a unit, so the reservations and front desk modules never
     * touch `facility_bookings`. A reservation with a live unit keeps its bookings (0). `$actor` is null when the
     * guest cancels online or the night audit marks the no-show. Returns how many were cancelled. Logs one
     * `facility_bookings.cancelled` on the reservation when there were any.
     * See docs/33-facilities/04-facility-bookings.md#no-shows.
     */
    public function forEndedReservation(?User $actor, Reservation $reservation): int
    {
        $hasLiveUnit = $reservation->units()
            ->whereNotIn('status', [UnitStatus::Cancelled->value, UnitStatus::NoShow->value])
            ->exists();

        return $hasLiveUnit ? 0 : $this->cancel($actor, $reservation, 'reservation_id');
    }

    private function cancel(?User $actor, Event|Reservation $owner, string $column): int
    {
        return DB::transaction(function () use ($actor, $owner, $column): int {
            $ids = FacilityBooking::query()
                ->where($column, $owner->id)
                ->live()
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return 0;
            }

            foreach (FacilityBooking::query()->whereKey($ids)->orderBy('id')->get() as $booking) {
                ($this->reverseCharge)($actor, $booking);
            }

            FacilityBooking::query()->whereKey($ids)->update([
                'status' => FacilityBookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor?->id,
                'updated_at' => now(),
            ]);

            activity()
                ->performedOn($owner)
                ->causedBy($actor)
                ->event('facility_bookings.cancelled')
                ->withProperties(['property_id' => $owner->property_id, 'facility_booking_ids' => $ids])
                ->log('Facility bookings cancelled');

            return count($ids);
        });
    }
}
