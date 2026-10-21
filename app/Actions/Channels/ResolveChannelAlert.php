<?php

namespace App\Actions\Channels;

use App\Models\ChannelAlert;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;

class ResolveChannelAlert
{
    /**
     * Close an alert an admin has dealt with. A closed alert is left as it is.
     */
    public function __invoke(User $actor, ChannelAlert $alert): ChannelActionResult
    {
        if ($alert->resolved_at !== null) {
            return ChannelActionResult::failed(__('The alert is already closed.'));
        }

        $alert->update(['resolved_at' => now()]);

        activity()
            ->performedOn($alert->channelProperty->property)
            ->causedBy($actor)
            ->event('channel_alert.resolved')
            ->withProperties(['alert_id' => $alert->id, 'event' => $alert->event->value])
            ->log('Channel alert closed');

        return ChannelActionResult::ok(__('Alert closed.'));
    }
}
