<?php

namespace Database\Factories;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventId = 'evt_'.Str::uuid7();

        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'property_id' => null,
            'event' => WebhookEvent::BookingCreated,
            'event_id' => $eventId,
            'payload' => ['id' => $eventId, 'event' => WebhookEvent::BookingCreated->value, 'version' => 1, 'occurred_at' => now()->toIso8601String(), 'test' => false, 'property' => null, 'data' => []],
            'is_test' => false,
            'status' => WebhookDeliveryStatus::Pending,
            'attempts' => 0,
            'response_status' => null,
            'error' => null,
            'next_attempt_at' => null,
            'delivered_at' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(['status' => WebhookDeliveryStatus::Failed, 'attempts' => WebhookDelivery::MAX_ATTEMPTS, 'response_status' => 500, 'error' => 'HTTP 500']);
    }
}
