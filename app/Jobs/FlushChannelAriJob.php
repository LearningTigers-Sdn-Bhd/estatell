<?php

namespace App\Jobs;

use App\Actions\Channels\FlushChannelAri;
use App\Models\ChannelAriChange;
use App\Models\ChannelProperty;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sends the waiting ARI changes of one Channex property: one /restrictions call and one /availability call. The first
 * change dispatches it with a short delay and later changes join the same flush, because only one job per property
 * waits at a time. A full run sends the next 500 days instead. A rate limit or Channex error puts it back with a
 * growing delay; no change is dropped.
 */
class FlushChannelAriJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public function __construct(public readonly int $channelPropertyId, public readonly bool $full = false) {}

    public function uniqueId(): string
    {
        return "channel-ari:{$this->channelPropertyId}:".($this->full ? 'full' : 'changes');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("channel-ari-flush:{$this->channelPropertyId}"))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(FlushChannelAri $flush): void
    {
        $channelProperty = ChannelProperty::query()->find($this->channelPropertyId);

        if ($channelProperty === null) {
            return;
        }

        $retryAfter = $flush($channelProperty, $this->full);

        if ($retryAfter !== null) {
            self::dispatch($this->channelPropertyId, $this->full)->delay(now()->addSeconds($retryAfter));

            return;
        }

        $waiting = ChannelAriChange::query()->where('channel_property_id', $this->channelPropertyId)->waiting()->whereNull('last_error')->exists();

        if ($waiting && ! $this->full) {
            self::dispatch($this->channelPropertyId)->delay(now()->addSeconds(random_int(10, 30)));
        }
    }
}
