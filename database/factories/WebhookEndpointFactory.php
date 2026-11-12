<?php

namespace Database\Factories;

use App\Enums\WebhookEvent;
use App\Models\Property;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => null,
            'name' => 'n8n – '.fake()->words(2, true),
            'url' => 'https://n8n.example.test/webhook/'.fake()->uuid(),
            'secret' => 'whsec_'.fake()->sha256(),
            'is_active' => true,
            'failing_since' => null,
            'disabled_at' => null,
            'disabled_reason' => null,
        ];
    }

    public function forProperty(Property $property): static
    {
        return $this->state(['property_id' => $property->id]);
    }

    public function off(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * @param  list<WebhookEvent>  $events
     */
    public function hearing(array $events): static
    {
        return $this->afterCreating(function (WebhookEndpoint $endpoint) use ($events): void {
            $endpoint->events()->createMany(array_map(fn (WebhookEvent $event): array => ['event' => $event], $events));
        });
    }
}
