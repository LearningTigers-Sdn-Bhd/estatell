<?php

namespace App\Jobs;

use App\Actions\Webhooks\DeliverWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one try of a webhook delivery. `DeliverWebhook` queues the next try itself with a delay, so this job never
 * retries. A delivery deleted with its endpoint is skipped.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $webhookDeliveryId) {}

    public function handle(DeliverWebhook $deliver): void
    {
        $delivery = WebhookDelivery::query()->find($this->webhookDeliveryId);

        if ($delivery !== null) {
            $deliver($delivery);
        }
    }
}
