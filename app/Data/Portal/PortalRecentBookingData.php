<?php

namespace App\Data\Portal;

use App\Data\Shared\BadgeData;
use App\Models\Reservation;
use Spatie\LaravelData\Data;

/**
 * One row of the dashboard's recent bookings. Expects `property`, `booker` and the first arrival and last departure of
 * the units, as `PortalRecentBookings` loads them.
 */
class PortalRecentBookingData extends Data
{
    public function __construct(
        public int $reservationId,
        public string $propertyName,
        public string $propertyCode,
        public string $reference,
        public ?string $externalReference,
        public string $title,
        public string $firstArrival,
        public string $lastDeparture,
        public BadgeData $status,
    ) {}

    public static function fromReservation(Reservation $reservation): self
    {
        return new self(
            reservationId: $reservation->id,
            propertyName: $reservation->property->name,
            propertyCode: $reservation->property->code,
            reference: $reservation->reference,
            externalReference: $reservation->external_reference,
            title: $reservation->group_name ?? $reservation->booker->full_name,
            firstArrival: (string) $reservation->getAttribute('first_arrival'),
            lastDeparture: (string) $reservation->getAttribute('last_departure'),
            status: PortalBookingRowData::status($reservation),
        );
    }
}
