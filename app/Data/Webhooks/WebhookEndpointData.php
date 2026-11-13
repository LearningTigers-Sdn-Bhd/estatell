<?php

namespace App\Data\Webhooks;

use App\Models\WebhookEndpoint;
use App\Models\WebhookEndpointEvent;
use Spatie\LaravelData\Data;

/**
 * One endpoint on the admin Webhooks list and endpoint page. The secret is never sent.
 */
class WebhookEndpointData extends Data
{
    public const array RELATIONS = ['property:id,name', 'events'];

    /**
     * @param  list<string>  $events
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public ?int $propertyId,
        public ?string $propertyName,
        public array $events,
        public bool $isActive,
        public ?string $failingSince,
        public ?string $disabledAt,
        public ?string $disabledReason,
    ) {}

    public static function fromModel(WebhookEndpoint $endpoint): self
    {
        return new self(
            id: $endpoint->id,
            name: $endpoint->name,
            url: $endpoint->url,
            propertyId: $endpoint->property_id,
            propertyName: $endpoint->property?->name,
            events: array_values($endpoint->events->map(fn (WebhookEndpointEvent $event): string => $event->event->value)->all()),
            isActive: $endpoint->is_active,
            failingSince: $endpoint->is_active ? $endpoint->failing_since?->format('Y-m-d H:i') : null,
            disabledAt: $endpoint->disabled_at?->format('Y-m-d H:i'),
            disabledReason: $endpoint->disabled_reason,
        );
    }
}
