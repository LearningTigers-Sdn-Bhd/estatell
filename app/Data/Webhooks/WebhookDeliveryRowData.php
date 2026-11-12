<?php

namespace App\Data\Webhooks;

use App\Data\Shared\BadgeData;
use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use Spatie\LaravelData\Data;

/**
 * One delivery in a deliveries list.
 */
class WebhookDeliveryRowData extends Data
{
    public const array RELATIONS = ['endpoint:id,name,is_active'];

    public function __construct(
        public int $id,
        public int $endpointId,
        public string $endpointName,
        public string $event,
        public string $eventLabel,
        public BadgeData $status,
        public int $attempts,
        public ?int $responseStatus,
        public bool $isTest,
        public bool $canRetry,
        public string $createdAt,
    ) {}

    public static function fromModel(WebhookDelivery $delivery): self
    {
        return new self(
            id: $delivery->id,
            endpointId: $delivery->webhook_endpoint_id,
            endpointName: $delivery->endpoint->name,
            event: $delivery->event->value,
            eventLabel: $delivery->event->label(),
            status: BadgeData::fromWebhookDeliveryStatus($delivery->status),
            attempts: $delivery->attempts,
            responseStatus: $delivery->response_status,
            isTest: $delivery->is_test,
            canRetry: $delivery->status->canTransitionTo(WebhookDeliveryStatus::Pending) && $delivery->endpoint->is_active,
            createdAt: $delivery->created_at?->format('Y-m-d H:i') ?? '',
        );
    }
}
