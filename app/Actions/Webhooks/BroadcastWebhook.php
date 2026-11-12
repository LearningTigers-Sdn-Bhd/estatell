<?php

namespace App\Actions\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhookJob;
use App\Models\Property;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookPayload;

/**
 * Hand one event to every active endpoint that hears it. Each endpoint gets its own delivery row and job, so one slow
 * or broken endpoint never holds up or repeats the others. The jobs run after the current transaction commits, so a
 * change that rolls back sends nothing. Returns how many deliveries were queued.
 */
class BroadcastWebhook
{
    public function __construct(private WebhookPayload $payload) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(WebhookEvent $event, ?Property $property, array $data): int
    {
        $endpoints = WebhookEndpoint::query()->listeningFor($event, $property?->id)->get(['id']);

        if ($endpoints->isEmpty()) {
            return 0;
        }

        $eventId = $this->payload->newEventId();
        $body = $this->payload->envelope($eventId, $event, $property, $data, now());

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::query()->create([
                'webhook_endpoint_id' => $endpoint->id,
                'property_id' => $property?->id,
                'event' => $event,
                'event_id' => $eventId,
                'payload' => $body,
                'status' => WebhookDeliveryStatus::Pending,
            ]);

            DeliverWebhookJob::dispatch($delivery->id)->afterCommit();
        }

        return $endpoints->count();
    }
}
