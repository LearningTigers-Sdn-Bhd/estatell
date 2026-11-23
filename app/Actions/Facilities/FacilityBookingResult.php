<?php

namespace App\Actions\Facilities;

use App\Models\FacilityBooking;

/**
 * The outcome of a facility booking Action, and of the event Actions that check bookings. A refusal names its code
 * and, when it is about one facility, that facility's name for the message.
 * See docs/33-facilities/04-facility-bookings.md#actions.
 */
final class FacilityBookingResult
{
    public const string REFUSAL_CLASH = 'facility_clash';

    public const string REFUSAL_FULL = 'facility_full';

    public const string REFUSAL_INACTIVE = 'facility_inactive';

    public const string REFUSAL_SESSION_NOT_FOUND = 'facility_session_not_found';

    public const string REFUSAL_FOLIO_NOT_OPEN = 'folio_not_open';

    public const string REFUSAL_OUTSIDE_EVENT = 'booking_outside_event';

    public const string REFUSAL_EVENT_NOT_OPEN = 'event_not_open';

    public const string REFUSAL_EVENT_NOT_TENTATIVE = 'event_not_tentative';

    public const string REFUSAL_BOOKING_CANCELLED = 'facility_booking_cancelled';

    public const string REFUSAL_RESERVATION_NOT_LIVE = 'reservation_not_live';

    public const string REFUSAL_FOLIO_NOT_OF_OWNER = 'folio_not_of_owner';

    private function __construct(
        public readonly ?FacilityBooking $booking,
        public readonly ?string $refusal,
        public readonly ?string $facilityName,
    ) {}

    public static function done(?FacilityBooking $booking = null): self
    {
        return new self($booking, null, null);
    }

    public static function refused(string $refusal, ?string $facilityName = null): self
    {
        return new self(null, $refusal, $facilityName);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
