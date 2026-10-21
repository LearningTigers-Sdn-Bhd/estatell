<?php

namespace App\Jobs;

use App\Actions\Channels\PullChannelRevisions;
use App\Models\ChannelProperty;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Pulls the unacknowledged booking revisions of one Channex property and saves them. The webhook and the scheduled
 * fallback both dispatch it. Only one waits per property, and two never run together, so a revision is never saved
 * twice at once. A rate limit puts it back after the wait Channex asked for.
 */
class PullChannelRevisionsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public function __construct(public readonly int $channelPropertyId) {}

    public function uniqueId(): string
    {
        return "channel-revisions:{$this->channelPropertyId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("channel-revisions:{$this->channelPropertyId}"))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(PullChannelRevisions $pull): void
    {
        $channelProperty = ChannelProperty::query()->find($this->channelPropertyId);

        if ($channelProperty === null) {
            return;
        }

        $retryAfter = $pull($channelProperty);

        if ($retryAfter !== null) {
            self::dispatch($this->channelPropertyId)->delay(now()->addSeconds($retryAfter));
        }
    }
}
