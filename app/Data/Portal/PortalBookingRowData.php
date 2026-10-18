<?php

namespace App\Data\Portal;

use App\Data\Shared\BadgeData;
use App\Enums\ReservationStatus;
use App\Enums\Tone;
use App\Models\Reservation;
use Spatie\LaravelData\Data;

/**
 * One row of the portal bookings list. Expects the list query of `PortalBookings`: the `booker`, the unit count and the
 * first arrival and last departure of the units. `total` is the rooms and add-ons of the live units, a decimal string.
 */
class PortalBookingRowData extends Data
{
    public function __construct(
        public int $id,
        public string $reference,
        public ?string $externalReference,
        public string $title,
        public string $firstArrival,
        public string $lastDeparture,
        public int $rooms,
        public string $total,
        public BadgeData $status,
    ) {}

    public static function fromReservation(Reservation $reservation, string $total): self
    {
        return new self(
            id: $reservation->id,
            reference: $reservation->reference,
            externalReference: $reservation->external_reference,
            title: $reservation->group_name ?? $reservation->booker->full_name,
            firstArrival: (string) $reservation->getAttribute('first_arrival'),
            lastDeparture: (string) $reservation->getAttribute('last_departure'),
            rooms: (int) $reservation->getAttribute('units_count'),
            total: $total,
            status: self::status($reservation),
        );
    }

    /**
     * A Confirmed booking with a cancellation request reads "Cancel requested".
     */
    public static function status(Reservation $reservation): BadgeData
    {
        if ($reservation->status === ReservationStatus::Confirmed && $reservation->cancellation_requested_at !== null) {
            return new BadgeData('cancel_requested', __('Cancel requested'), Tone::Warning);
        }

        return BadgeData::fromReservationStatus($reservation->status);
    }
}
