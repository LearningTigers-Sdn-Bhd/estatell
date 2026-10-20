<?php

namespace App\Actions\Channels;

use App\Enums\ChannelSyncState;
use App\Jobs\SyncChannelStructureJob;
use App\Models\Property;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;
use App\Services\Channels\ChannelStructurePreview;
use App\Services\Channels\ChannelSyncProgress;

class StartChannelStructureSync
{
    public function __construct(
        private ChannelStructurePreview $preview,
        private ChannelSyncProgress $progress,
        private RecordChannelSyncProgress $recordProgress,
    ) {}

    /**
     * Queue the structure sync of a property, so the admin's request returns at once. The sync can take minutes: it
     * makes a call to Channex for the property, every room type and every rate plan. Refuses, without queueing, when
     * Channex is not set up, the preview is blocked, or a sync of the property is already waiting or running. The job
     * reports its steps through `RecordChannelSyncProgress` and the tab polls them.
     */
    public function __invoke(User $admin, Property $property): ChannelActionResult
    {
        if (blank(config('services.channex.api_key'))) {
            return ChannelActionResult::failed(__('Channex is not set up on this server.'));
        }

        if ($this->progress->isActive($property->id)) {
            return ChannelActionResult::failed(__('A sync of this property is already running.'));
        }

        $property->loadMissing('settings');
        $blocked = $this->preview->forProperty($property)->blockedReason;

        if ($blocked !== null) {
            return ChannelActionResult::failed($blocked);
        }

        ($this->recordProgress)($property->id, ChannelSyncState::Queued);
        SyncChannelStructureJob::dispatch($property->id, $admin->id);

        return ChannelActionResult::ok(__('The sync to Channex started. It runs in the background.'));
    }
}
