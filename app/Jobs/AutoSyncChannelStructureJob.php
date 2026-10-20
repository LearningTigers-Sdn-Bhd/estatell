<?php

namespace App\Jobs;

use App\Actions\Channels\RecordChannelAlert;
use App\Actions\Channels\RecordChannelSyncProgress;
use App\Actions\Channels\ScheduleChannelStructureSync;
use App\Actions\Channels\SyncChannelStructure;
use App\Enums\ChannelAlertEvent;
use App\Enums\ChannelSyncState;
use App\Features\ChannelManager;
use App\Models\ChannelProperty;
use App\Services\Channels\ChannelSyncProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Sends a property's structure to the channel manager after staff changed it, the same sync an admin starts by hand.
 * Every change starts a job with a token and a delay; only the job whose token is still in the cache runs, so edits in
 * a row make one sync. A failure shows as an alert on the Channels tab and in the tab's last error; the nightly sync
 * and the admin's Sync again are the way out. See docs/21-channels/02-mappings-and-capability.md#background-sync.
 */
class AutoSyncChannelStructureJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly int $propertyId, public readonly string $token) {}

    public function handle(SyncChannelStructure $sync, RecordChannelSyncProgress $record, ChannelSyncProgress $progress, RecordChannelAlert $recordAlert): void
    {
        $key = ScheduleChannelStructureSync::key($this->propertyId);

        if (Cache::get($key) !== $this->token) {
            return;
        }

        $channelProperty = ChannelProperty::query()->with('property')->where('property_id', $this->propertyId)->first();

        if ($channelProperty === null || ! Feature::for($channelProperty->property)->active(ChannelManager::class)) {
            Cache::forget($key);

            return;
        }

        if ($progress->isActive($this->propertyId)) {
            $this->release(60);

            return;
        }

        Cache::forget($key);
        $record($this->propertyId, ChannelSyncState::Running);

        $result = $sync(null, $channelProperty->property, fn (int $done, int $total) => $record($this->propertyId, ChannelSyncState::Running, $done, $total));

        $record($this->propertyId, $result->ok ? ChannelSyncState::Done : ChannelSyncState::Failed, message: $result->message);

        if (! $result->ok) {
            $recordAlert($channelProperty, ChannelAlertEvent::StructureSyncFailed, ['message' => $result->message]);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(RecordChannelSyncProgress::class)($this->propertyId, ChannelSyncState::Failed, message: __('The sync stopped unexpectedly. Check the logs, then try again.'));
    }
}
