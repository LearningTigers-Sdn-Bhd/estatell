<?php

namespace App\Data\Webhooks;

use App\Enums\WebhookEvent;
use App\Http\Requests\Admin\WebhookEndpointRequest;
use Spatie\LaravelData\Data;

/**
 * A validated endpoint from the Add endpoint and Edit Sheet.
 */
class WebhookEndpointInputData extends Data
{
    /**
     * @param  list<WebhookEvent>  $events
     */
    public function __construct(
        public string $name,
        public string $url,
        public ?int $propertyId,
        public array $events,
        public bool $isActive,
    ) {}

    public static function fromRequest(WebhookEndpointRequest $request): self
    {
        $propertyId = $request->input('property_id');

        return new self(
            name: $request->string('name')->trim()->toString(),
            url: $request->string('url')->trim()->toString(),
            propertyId: $propertyId === null || $propertyId === '' ? null : (int) $propertyId,
            events: array_values(array_map(fn (string $event): WebhookEvent => WebhookEvent::from($event), array_unique($request->array('events')))),
            isActive: $request->boolean('is_active', true),
        );
    }
}
