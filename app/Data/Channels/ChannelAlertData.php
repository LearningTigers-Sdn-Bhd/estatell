<?php

namespace App\Data\Channels;

use App\Data\Shared\BadgeData;
use App\Models\ChannelAlert;
use Spatie\LaravelData\Data;

/**
 * One open alert on the admin Channels tab.
 */
class ChannelAlertData extends Data
{
    public function __construct(
        public int $id,
        public BadgeData $event,
        public ?string $bookingId,
        public ?string $detail,
        public string $createdAt,
    ) {}

    public static function fromModel(ChannelAlert $alert): self
    {
        $payload = $alert->payload;
        $detail = match (true) {
            isset($payload['message']) => (string) $payload['message'],
            isset($payload['channel_name']) => trim($payload['channel_name'].' '.($payload['error_type'] ?? '')),
            isset($payload['booking_unique_id']) => (string) $payload['booking_unique_id'],
            default => null,
        };

        return new self(
            id: $alert->id,
            event: BadgeData::fromChannelAlertEvent($alert->event),
            bookingId: $alert->external_booking_id,
            detail: $detail === '' ? null : $detail,
            createdAt: $alert->created_at->toIso8601String(),
        );
    }
}
