<?php

namespace Database\Factories;

use App\Enums\PosPaymentDirection;
use App\Enums\PosPaymentStatus;
use App\Enums\PosReconciliationStatus;
use App\Enums\PosTenderType;
use App\Models\PaymentMethod;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PosOrderPayment>
 */
class PosOrderPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_order_id' => fn (array $attributes): int => PosOrder::factory()->create(['property_id' => $attributes['property_id']])->id,
            'pos_shift_id' => fn (array $attributes): int => (int) PosOrder::query()->whereKey($attributes['pos_order_id'])->value('pos_shift_id'),
            'idempotency_key' => (string) Str::uuid(),
            'payment_method_id' => fn (array $attributes): int => PaymentMethod::factory()->create(['property_id' => $attributes['property_id']])->id,
            'direction' => PosPaymentDirection::In,
            'tender_type' => PosTenderType::Cash,
            'reconciliation_status' => PosReconciliationStatus::Verified,
            'amount' => '10.00',
            'rounding_adjustment' => '0.00',
            'status' => PosPaymentStatus::Captured,
            'acted_by_user_id' => fn (array $attributes): int => User::factory()->create(['property_id' => $attributes['property_id']])->id,
            'paid_at' => now(),
        ];
    }
}
