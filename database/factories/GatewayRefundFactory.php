<?php

namespace Database\Factories;

use App\Enums\GatewayRefundStatus;
use App\Models\GatewayPayment;
use App\Models\GatewayRefund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GatewayRefund>
 */
class GatewayRefundFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gateway_payment_id' => GatewayPayment::factory(),
            'property_id' => fn (array $attributes) => GatewayPayment::query()->whereKey($attributes['gateway_payment_id'])->value('property_id'),
            'idempotency_key' => fake()->unique()->uuid(),
            'amount' => '200.00',
            'status' => GatewayRefundStatus::Pending,
            'attempts' => 1,
        ];
    }
}
