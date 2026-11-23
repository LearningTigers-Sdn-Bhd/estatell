<?php

namespace App\Services\Facilities;

use App\Actions\Facilities\FacilityBookingResult;
use App\Data\Facilities\FacilityBookingInputData;
use App\Enums\EventStatus;
use App\Enums\UnitStatus;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Reservation;

/**
 * The checks `BookFacility` and `UpdateFacilityBooking` share, run after the owner (an event or a reservation) and the
 * facility rows are locked. Returns the refusal code, or null when the booking may be saved.
 */
class FacilityBookingChecks
{
    public function __construct(private FacilityAvailability $availability) {}

    /**
     * `$event` may be unsaved: the New event page checks its lines before the event exists. `$pending` are the
     * earlier lines of that page.
     *
     * @param  iterable<FacilityBooking>  $pending
     */
    public function forEvent(Event $event, Facility $facility, FacilityBookingInputData $data, ?int $ignoreBookingId = null, iterable $pending = []): ?string
    {
        if (! in_array($event->status, [EventStatus::Tentative, EventStatus::Confirmed], true)) {
            return FacilityBookingResult::REFUSAL_EVENT_NOT_OPEN;
        }

        if ($data->bookingDate < $event->starts_on->toDateString() || $data->bookingDate > $event->ends_on->toDateString()) {
            return FacilityBookingResult::REFUSAL_OUTSIDE_EVENT;
        }

        return $this->fits($facility, $data, $ignoreBookingId, $pending);
    }

    /**
     * A reservation takes bookings while it has a Confirmed or In-house unit (`reservation_not_live`). Any date is
     * allowed, also outside the stay (a dinner the night before arrival). Charge to is checked by the Action.
     */
    public function forReservation(Reservation $reservation, Facility $facility, FacilityBookingInputData $data, ?int $ignoreBookingId = null): ?string
    {
        if (! $this->isLive($reservation)) {
            return FacilityBookingResult::REFUSAL_RESERVATION_NOT_LIVE;
        }

        return $this->fits($facility, $data, $ignoreBookingId);
    }

    /**
     * Whether the reservation has a Confirmed or In-house unit, so it may take facility bookings.
     */
    public function isLive(Reservation $reservation): bool
    {
        return $reservation->units()->whereIn('status', [UnitStatus::Confirmed->value, UnitStatus::CheckedIn->value])->exists();
    }

    /**
     * The session and the clash check, the same for both owners.
     *
     * @param  iterable<FacilityBooking>  $pending
     */
    private function fits(Facility $facility, FacilityBookingInputData $data, ?int $ignoreBookingId, iterable $pending = []): ?string
    {
        if ($facility->sessions()->exists()
            && ! $facility->sessions()->where('starts_at', $data->startsAt)->where('ends_at', $data->endsAt)->exists()) {
            return FacilityBookingResult::REFUSAL_SESSION_NOT_FOUND;
        }

        return $this->availability->refusalFor($facility, $data->bookingDate, $data->startsAt, $data->endsAt, $data->quantity, $ignoreBookingId, $pending);
    }
}
