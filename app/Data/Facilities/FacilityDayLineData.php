<?php

namespace App\Data\Facilities;

use App\Data\Shared\BadgeData;
use App\Models\FacilityBooking;
use Spatie\LaravelData\Data;

/**
 * One booking in the facility day peek of the Timeline View. Times are `HH:MM`. A booking belongs to an event
 * (`eventId`) or a reservation (`reservationId`), never both; the peek links to its page. `quantity` and
 * `quantityUnit` read as "2 people". It carries no contact or ID data.
 */
class FacilityDayLineData extends Data
{
    public function __construct(
        public int $id,
        public string $startsAt,
        public string $endsAt,
        public string $name,
        public ?int $eventId,
        public ?int $reservationId,
        public int $quantity,
        public string $quantityUnit,
        public BadgeData $status,
    ) {}

    /**
     * Expects `facility`, `event` and `reservation.booker` to be loaded.
     */
    public static function fromModel(FacilityBooking $booking): self
    {
        return new self(
            id: $booking->id,
            startsAt: substr($booking->starts_at, 0, 5),
            endsAt: substr($booking->ends_at, 0, 5),
            name: self::nameOf($booking),
            eventId: $booking->event_id,
            reservationId: $booking->reservation_id,
            quantity: $booking->quantity,
            quantityUnit: $booking->facility->booked_by->unit($booking->quantity),
            status: BadgeData::fromFacilityBookingStatus($booking->status),
        );
    }

    /**
     * The event name, or the booker and the reservation reference ("Lee Wei · ES-2041").
     */
    public static function nameOf(FacilityBooking $booking): string
    {
        if ($booking->event !== null) {
            return $booking->event->name;
        }

        $reservation = $booking->reservation;

        return collect([$reservation?->booker?->full_name, $reservation?->reference])->filter()->implode(' · ');
    }
}
