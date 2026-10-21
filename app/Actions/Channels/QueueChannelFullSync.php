<?php

namespace App\Actions\Channels;

use App\Enums\ChannelPropertyStatus;
use App\Jobs\FlushChannelAriJob;
use App\Models\ChannelProperty;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;

class QueueChannelFullSync
{
    /** Channex allows one full sync per property per 24 hours. An admin's Full refresh waits that long. */
    public const ADMIN_INTERVAL_HOURS = 24;

    /** The nightly run skips a property that had a full sync in the last 12 hours, such as its first sync. */
    public const NIGHTLY_INTERVAL_HOURS = 12;

    /**
     * Queue the 500-day full sync: one availability call and one rates and restrictions call. An admin passes
     * themself and is refused inside 24 hours of the last full sync and while the property is not synced. The first
     * sync and the nightly run pass no admin; the nightly run skips a property that was fully synced in the last
     * 12 hours. `$delaySeconds` staggers the nightly runs.
     */
    public function __invoke(ChannelProperty $channelProperty, ?User $admin = null, int $delaySeconds = 0): ChannelActionResult
    {
        if ($channelProperty->status !== ChannelPropertyStatus::Synced) {
            return ChannelActionResult::failed(__('Sync the structure to Channex first.'));
        }

        $hours = $admin === null ? self::NIGHTLY_INTERVAL_HOURS : self::ADMIN_INTERVAL_HOURS;

        if ($channelProperty->full_synced_at?->gt(now()->subHours($hours))) {
            return ChannelActionResult::failed(__('A full refresh ran less than :hours hours ago. Channex allows one a day.', ['hours' => $hours]));
        }

        FlushChannelAriJob::dispatch($channelProperty->id, true)->delay(now()->addSeconds($delaySeconds));

        if ($admin !== null) {
            activity()
                ->performedOn($channelProperty->property)
                ->causedBy($admin)
                ->event('channel.full_refresh_queued')
                ->withProperties(['property_id' => $channelProperty->property_id])
                ->log('Full refresh to Channex queued');
        }

        return ChannelActionResult::ok(__('Full refresh queued. The next 500 days are sent in a moment.'));
    }
}
