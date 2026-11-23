<?php

namespace App\Services\Facilities;

use App\Actions\Facilities\FacilityBookingResult;
use App\Data\Facilities\FacilityBookingInputData;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;

/**
 * The checks `BookFacility` and `UpdateFacilityBooking` share for an event's booking, run after the event and the
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

        if ($facility->sessions()->exists()
            && ! $facility->sessions()->where('starts_at', $data->startsAt)->where('ends_at', $data->endsAt)->exists()) {
            return FacilityBookingResult::REFUSAL_SESSION_NOT_FOUND;
        }

        return $this->availability->refusalFor($facility, $data->bookingDate, $data->startsAt, $data->endsAt, $data->quantity, $ignoreBookingId, $pending);
    }
}
