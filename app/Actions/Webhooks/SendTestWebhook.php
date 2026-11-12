<?php

namespace App\Actions\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookPayload;
use App\Services\Webhooks\WebhookSampleData;

class SendTestWebhook
{
    public function __construct(private WebhookPayload $payload, private WebhookSampleData $samples) {}

    /**
     * Send made-up sample data in the real shape of one event, with `"test": true`. It goes to this endpoint only, even
     * when the endpoint is off. Returns null when the endpoint does not hear the event.
     */
    public function __invoke(WebhookEndpoint $endpoint, WebhookEvent $event): ?WebhookDelivery
    {
        if (! $endpoint->events()->where('event', $event->value)->exists()) {
            return null;
        }

        $endpoint->loadMissing('property.settings');
        $eventId = $this->payload->newEventId();

        $delivery = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'property_id' => $endpoint->property_id,
            'event' => $event,
            'event_id' => $eventId,
            'payload' => $this->payload->envelope($eventId, $event, $endpoint->property, $this->samples->for($event), now(), isTest: true),
            'is_test' => true,
            'status' => WebhookDeliveryStatus::Pending,
        ]);

        DeliverWebhookJob::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }
}
