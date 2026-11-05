<?php

namespace Database\Factories;

use App\Models\GatewayEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GatewayEvent>
 */
class GatewayEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gateway_account' => 'primary',
            'event_id' => 'evt_'.fake()->unique()->bothify('##########'),
            'event_type' => 'order.paid',
            'payload' => [],
        ];
    }
}
