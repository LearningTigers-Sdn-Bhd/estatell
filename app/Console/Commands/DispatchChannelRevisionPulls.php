<?php

namespace App\Console\Commands;

use App\Enums\ChannelPropertyStatus;
use App\Jobs\PullChannelRevisionsJob;
use App\Models\ChannelProperty;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('channels:pull-revisions')]
#[Description('Queue a pull of the unacknowledged booking revisions for every property on Channex, as a fallback for missed webhooks')]
class DispatchChannelRevisionPulls extends Command
{
    public function handle(): int
    {
        $count = 0;

        foreach (ChannelProperty::query()->whereIn('status', [ChannelPropertyStatus::Synced, ChannelPropertyStatus::Paused])->orderBy('id')->pluck('id') as $id) {
            PullChannelRevisionsJob::dispatch($id);
            $count++;
        }

        $this->info("Queued {$count} revision pulls.");

        return self::SUCCESS;
    }
}
