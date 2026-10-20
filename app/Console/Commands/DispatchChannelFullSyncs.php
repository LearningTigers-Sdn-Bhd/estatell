<?php

namespace App\Console\Commands;

use App\Actions\Channels\QueueChannelFullSync;
use App\Enums\ChannelPropertyStatus;
use App\Features\ChannelManager;
use App\Models\ChannelProperty;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;

#[Signature('channels:full-sync')]
#[Description('Queue the nightly 500-day full sync for every property on Channex, a few seconds apart')]
class DispatchChannelFullSyncs extends Command
{
    /** Seconds between two properties, so one run does not hit Channex all at once. */
    private const STAGGER_SECONDS = 5;

    public function handle(QueueChannelFullSync $queueChannelFullSync): int
    {
        $index = 0;

        foreach (ChannelProperty::query()->with('property')->where('status', ChannelPropertyStatus::Synced)->orderBy('id')->get() as $channelProperty) {
            if (! Feature::for($channelProperty->property)->active(ChannelManager::class)) {
                continue;
            }

            if ($queueChannelFullSync($channelProperty, null, $index * self::STAGGER_SECONDS)->ok) {
                $index++;
            }
        }

        $this->info("Queued {$index} full syncs.");

        return self::SUCCESS;
    }
}
