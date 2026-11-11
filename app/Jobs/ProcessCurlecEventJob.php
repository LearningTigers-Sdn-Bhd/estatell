<?php

namespace App\Jobs;

use App\Actions\Payments\ProcessCurlecEvent;
use App\Models\GatewayEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Processes one saved Curlec event. When the booking is refused, the job waits and tries again, so a short problem heals
 * by itself. The event stays unprocessed if every try fails.
 */
class ProcessCurlecEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $gatewayEventId) {}

    public function handle(ProcessCurlecEvent $process): void
    {
        $event = GatewayEvent::query()->find($this->gatewayEventId);

        if ($event !== null && ! $process($event, $this->attempts() === 1)) {
            $this->release(60 * $this->attempts());
        }
    }
}
