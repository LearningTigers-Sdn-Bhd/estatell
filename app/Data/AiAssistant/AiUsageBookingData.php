<?php

namespace App\Data\AiAssistant;

use App\Data\Shared\BadgeData;
use App\Models\Reservation;
use Spatie\LaravelData\Data;

/**
 * A booking made from a quote link the AI assistant sent, with its room total in the property currency. `bookedAt` is
 * the date it was made, in the property's time zone.
 */
class AiUsageBookingData extends Data
{
    public function __construct(
        public int $id,
        public string $reference,
        public BadgeData $status,
        public string $bookedAt,
        public string $roomTotal,
    ) {}

    public static function fromReservation(Reservation $reservation, string $roomTotal, string $timeZone): self
    {
        return new self(
            id: $reservation->id,
            reference: $reservation->reference,
            status: BadgeData::fromReservationStatus($reservation->status),
            bookedAt: (string) $reservation->created_at?->setTimezone($timeZone)->toDateString(),
            roomTotal: $roomTotal,
        );
    }
}
