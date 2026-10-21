<?php

namespace App\Actions\Channels;

use App\Models\ChannelBookingRevision;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;

class RetryChannelRevision
{
    public function __construct(private IngestChannelBooking $ingestChannelBooking) {}

    /**
     * Save a revision that was refused, for example after the admin fixed the mapping. A revision that is already
     * saved and acknowledged is left alone.
     */
    public function __invoke(User $actor, ChannelBookingRevision $revision): ChannelActionResult
    {
        if ($revision->acknowledged_at !== null) {
            return ChannelActionResult::failed(__('The booking is already saved.'));
        }

        $result = ($this->ingestChannelBooking)($revision);

        activity()
            ->performedOn($revision->channelProperty->property)
            ->causedBy($actor)
            ->event('channel_revision.retried')
            ->withProperties(['revision_id' => $revision->revision_id, 'ok' => $result->ok])
            ->log('Channel booking retried');

        return $result;
    }
}
