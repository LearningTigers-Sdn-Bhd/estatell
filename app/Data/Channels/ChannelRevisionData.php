<?php

namespace App\Data\Channels;

use App\Data\Shared\BadgeData;
use App\Models\ChannelBookingRevision;
use Spatie\LaravelData\Data;

/**
 * One booking revision on the admin Channels tab: a row of Recent bookings, or a booking that was not saved.
 */
class ChannelRevisionData extends Data
{
    public function __construct(
        public int $id,
        public string $bookingCode,
        public string $channel,
        public BadgeData $kind,
        public ?string $guest,
        public ?string $arrival,
        public ?string $departure,
        public int $rooms,
        public ?string $reservationReference,
        public ?string $error,
        public bool $saved,
        public string $receivedAt,
    ) {}

    /**
     * Expects `reservation` loaded.
     */
    public static function fromModel(ChannelBookingRevision $revision): self
    {
        $payload = $revision->payload;
        $customer = $payload['customer'] ?? [];
        $guest = trim(trim((string) ($customer['name'] ?? '')).' '.trim((string) ($customer['surname'] ?? '')));

        return new self(
            id: $revision->id,
            bookingCode: (string) ($payload['unique_id'] ?? $payload['ota_reservation_code'] ?? $revision->external_booking_id),
            channel: (string) ($payload['ota_name'] ?? ''),
            kind: BadgeData::fromRevisionKind($revision->kind),
            guest: $guest === '' ? null : $guest,
            arrival: $payload['arrival_date'] ?? null,
            departure: $payload['departure_date'] ?? null,
            rooms: count($payload['rooms'] ?? []),
            reservationReference: $revision->reservation?->reference,
            error: $revision->error,
            saved: $revision->processed_at !== null,
            receivedAt: $revision->created_at->toIso8601String(),
        );
    }
}
