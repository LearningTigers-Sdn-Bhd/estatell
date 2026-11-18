<?php

namespace Database\Factories;

use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentMode;
use App\Models\BookingHold;
use App\Models\GatewayPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GatewayPayment>
 */
class GatewayPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_hold_id' => BookingHold::factory(),
            'property_id' => fn (array $attributes) => BookingHold::query()->whereKey($attributes['booking_hold_id'])->value('property_id'),
            'gateway_account' => 'primary',
            'mode' => PaymentMode::Live,
            'order_id' => 'order_'.fake()->unique()->bothify('##########'),
            'amount' => '200.00',
            'status' => GatewayPaymentStatus::Created,
        ];
    }
}
