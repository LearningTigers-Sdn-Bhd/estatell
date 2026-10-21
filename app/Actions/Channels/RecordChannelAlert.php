<?php

namespace App\Actions\Channels;

use App\Enums\ChannelAlertEvent;
use App\Models\ChannelAlert;
use App\Models\ChannelProperty;

class RecordChannelAlert
{
    /**
     * Keep a problem the provider reported by webhook. The same event for the same revision is kept once while it is
     * open, because the provider repeats `non_acked_booking` until the booking is acknowledged.
     *
     * @param  array<string, mixed>  $payload  The webhook `payload`.
     */
    public function __invoke(ChannelProperty $channelProperty, ChannelAlertEvent $event, array $payload): ChannelAlert
    {
        $revisionId = $payload['booking_revision_id'] ?? $payload['revision_id'] ?? null;
        $revisionId = is_string($revisionId) ? $revisionId : null;

        if ($revisionId !== null) {
            $existing = ChannelAlert::query()
                ->where('channel_property_id', $channelProperty->id)
                ->where('event', $event)
                ->where('revision_id', $revisionId)
                ->open()
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $bookingId = $payload['booking_id'] ?? null;

        return ChannelAlert::query()->create([
            'channel_property_id' => $channelProperty->id,
            'event' => $event,
            'external_booking_id' => is_string($bookingId) ? $bookingId : null,
            'revision_id' => $revisionId,
            'payload' => $payload,
        ]);
    }
}
