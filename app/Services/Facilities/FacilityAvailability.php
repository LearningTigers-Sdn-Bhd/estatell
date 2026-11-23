<?php

namespace App\Services\Facilities;

use App\Actions\Facilities\FacilityBookingResult;
use App\Enums\FacilityBookingStatus;
use App\Enums\FacilitySharing;
use App\Models\Facility;
use App\Models\FacilityBooking;
use Illuminate\Support\Collection;

/**
 * The clash check for one facility, one date and one time range. Only Confirmed bookings block: a Private facility
 * takes no overlapping booking, a Shared one takes bookings while their quantity at every moment stays within its
 * capacity. Times overlap when one starts before the other ends, so 09:00–18:00 and 18:00–23:00 do not.
 * See docs/33-facilities/04-facility-bookings.md#clash-check.
 */
class FacilityAvailability
{
    /**
     * `facility_clash` or `facility_full` when the booking does not fit, otherwise null. `$ignoreBookingId` leaves out
     * the booking being changed or confirmed.
     */
    public function refusalFor(Facility $facility, string $date, string $startsAt, string $endsAt, int $quantity, ?int $ignoreBookingId = null): ?string
    {
        $confirmed = $this->overlapping($facility, $date, $startsAt, $endsAt, $ignoreBookingId)
            ->where('status', FacilityBookingStatus::Confirmed);

        if ($facility->sharing === FacilitySharing::Private) {
            return $confirmed->isEmpty() ? null : FacilityBookingResult::REFUSAL_CLASH;
        }

        return $this->peak($confirmed, $startsAt, $endsAt) + $quantity > $facility->capacity
            ? FacilityBookingResult::REFUSAL_FULL
            : null;
    }

    /**
     * What is left of a Shared facility over the whole range: its capacity less the most Confirmed bookings take at
     * any moment of it. Never below 0.
     */
    public function placesLeft(Facility $facility, string $date, string $startsAt, string $endsAt, ?int $ignoreBookingId = null): int
    {
        $confirmed = $this->overlapping($facility, $date, $startsAt, $endsAt, $ignoreBookingId)
            ->where('status', FacilityBookingStatus::Confirmed);

        return max(0, $facility->capacity - $this->peak($confirmed, $startsAt, $endsAt));
    }

    /**
     * The Tentative bookings of other events and reservations that overlap the range, for the warning. They never block.
     *
     * @return Collection<int, FacilityBooking>
     */
    public function tentativeHolds(Facility $facility, string $date, string $startsAt, string $endsAt, ?int $ignoreBookingId = null, ?int $exceptEventId = null): Collection
    {
        return $this->overlapping($facility, $date, $startsAt, $endsAt, $ignoreBookingId)
            ->where('status', FacilityBookingStatus::Tentative)
            ->filter(fn (FacilityBooking $booking): bool => $exceptEventId === null || $booking->event_id !== $exceptEventId)
            ->values();
    }

    /**
     * Live bookings of the facility on the date whose time overlaps the range.
     *
     * @return Collection<int, FacilityBooking>
     */
    private function overlapping(Facility $facility, string $date, string $startsAt, string $endsAt, ?int $ignoreBookingId): Collection
    {
        return FacilityBooking::query()
            ->where('property_id', $facility->property_id)
            ->where('facility_id', $facility->id)
            ->whereDate('booking_date', $date)
            ->live()
            ->where('starts_at', '<', self::time($endsAt))
            ->where('ends_at', '>', self::time($startsAt))
            ->when($ignoreBookingId !== null, fn ($query) => $query->whereKeyNot($ignoreBookingId))
            ->with('event:id,name')
            ->ordered()
            ->get();
    }

    /**
     * The largest quantity the bookings take at one moment inside the range. The quantity only rises when a booking
     * starts, so the start of the range and every booking start inside it are enough to check.
     *
     * @param  Collection<int, FacilityBooking>  $bookings
     */
    private function peak(Collection $bookings, string $startsAt, string $endsAt): int
    {
        $from = self::time($startsAt);
        $to = self::time($endsAt);
        $moments = $bookings
            ->toBase()
            ->map(fn (FacilityBooking $booking): string => self::time($booking->starts_at))
            ->filter(fn (string $start): bool => $start > $from && $start < $to)
            ->push($from)
            ->unique();

        return (int) $moments
            ->map(fn (string $moment): int => (int) $bookings
                ->filter(fn (FacilityBooking $booking): bool => self::time($booking->starts_at) <= $moment && self::time($booking->ends_at) > $moment)
                ->sum('quantity'))
            ->max();
    }

    /** `HH:MM` or `HH:MM:SS` as `HH:MM:SS`, so times compare as strings. */
    private static function time(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : substr($time, 0, 8);
    }
}
