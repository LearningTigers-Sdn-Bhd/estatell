<?php

namespace App\Actions\Channels;

use App\Events\ChannelStructureChanged;
use App\Features\ChannelManager;
use App\Jobs\AutoSyncChannelStructureJob;
use App\Models\ChannelProperty;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

class ScheduleChannelStructureSync
{
    /** Minutes to wait after the last change, so several edits in a row make one sync. */
    public const WAIT_MINUTES = 2;

    public static function key(int $propertyId): string
    {
        return "channel-structure-auto-sync:{$propertyId}";
    }

    /**
     * Send the structure to the channel manager again a short while after the last change. Each change starts the wait
     * again, and only the job of the latest change runs (it finds its own token in the cache). Does nothing for a
     * property that has not been synced yet (an admin starts the first sync) or that has the channel manager off.
     */
    public function __invoke(ChannelStructureChanged $event): void
    {
        $channelProperty = ChannelProperty::query()->with('property')->where('property_id', $event->propertyId)->first();

        if ($channelProperty === null || ! Feature::for($channelProperty->property)->active(ChannelManager::class)) {
            return;
        }

        $token = (string) Str::uuid();
        Cache::put(self::key($event->propertyId), $token, now()->addMinutes(self::WAIT_MINUTES + 30));

        AutoSyncChannelStructureJob::dispatch($event->propertyId, $token)->delay(now()->addMinutes(self::WAIT_MINUTES))->afterCommit();
    }
}
