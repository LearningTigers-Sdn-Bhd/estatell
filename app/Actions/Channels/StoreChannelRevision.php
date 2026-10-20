<?php

namespace App\Actions\Channels;

use App\Enums\RevisionKind;
use App\Models\ChannelBookingRevision;
use App\Models\ChannelProperty;

class StoreChannelRevision
{
    /**
     * Keep a revision from the feed as it arrived. The unique `revision_id` makes a duplicate harmless: the row that
     * exists is returned and nothing is written. A revision with an unknown kind is not stored (null), so it stays
     * unacknowledged at the provider and shows there.
     *
     * @param  array<string, mixed>  $revision  The revision as the driver returns it, with its `id`.
     */
    public function __invoke(ChannelProperty $channelProperty, array $revision): ?ChannelBookingRevision
    {
        $kind = RevisionKind::tryFrom((string) ($revision['status'] ?? ''));

        if ($kind === null || blank($revision['id'] ?? null) || blank($revision['booking_id'] ?? null)) {
            return null;
        }

        return ChannelBookingRevision::query()->firstOrCreate(
            ['revision_id' => (string) $revision['id']],
            [
                'channel_property_id' => $channelProperty->id,
                'external_booking_id' => (string) $revision['booking_id'],
                'kind' => $kind,
                'payload' => $revision,
            ],
        );
    }
}
