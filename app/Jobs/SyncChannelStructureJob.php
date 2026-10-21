<?php

namespace App\Jobs;

use App\Actions\Channels\RecordChannelSyncProgress;
use App\Actions\Channels\SyncChannelStructure;
use App\Enums\ChannelSyncState;
use App\Models\Property;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a property's room types and rate plans to Channex in the background and reports each step, so the admin tab can
 * show the progress. One call per item, so a big property takes minutes. The timeout is longer than the worker's
 * default, and the redis `retry_after` is longer still, so a running sync is never started a second time.
 */
class SyncChannelStructureJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly int $propertyId, public readonly int $adminId) {}

    public function handle(SyncChannelStructure $sync, RecordChannelSyncProgress $record): void
    {
        $property = Property::query()->find($this->propertyId);
        $admin = User::query()->find($this->adminId);

        if ($property === null || $admin === null) {
            return;
        }

        $record($this->propertyId, ChannelSyncState::Running);

        $result = $sync($admin, $property, fn (int $done, int $total) => $record($this->propertyId, ChannelSyncState::Running, $done, $total));

        $record($this->propertyId, $result->ok ? ChannelSyncState::Done : ChannelSyncState::Failed, message: $result->message);
    }

    public function failed(Throwable $exception): void
    {
        app(RecordChannelSyncProgress::class)($this->propertyId, ChannelSyncState::Failed, message: __('The sync stopped unexpectedly. Check the logs, then try again.'));
    }
}
