<?php

namespace Database\Factories;

use App\Enums\PolicyChargeMode;
use App\Enums\PolicyRateBasis;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\RoomRevenuePolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomRevenuePolicy>
 */
class RoomRevenuePolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'code' => SystemTransactionCode::CancellationRevenue,
            'charge_mode' => PolicyChargeMode::Free,
            'amount' => null,
            'percent' => null,
            'percent_basis' => null,
            'applies_to' => PolicyScope::FirstNight,
            'free_until_days' => null,
            'own_taxes' => false,
            'text_en' => 'No charge for cancellation.',
            'text_ms' => 'Tiada caj untuk pembatalan.',
        ];
    }

    public function forCode(SystemTransactionCode $code, PolicyScope $scope): static
    {
        return $this->state(fn (array $attributes) => ['code' => $code, 'applies_to' => $scope]);
    }

    public function percentage(string $percent = '100.00', PolicyRateBasis $basis = PolicyRateBasis::BookedRate): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_mode' => PolicyChargeMode::Percentage,
            'percent' => $percent,
            'percent_basis' => $basis,
        ]);
    }

    public function fixed(string $amount = '50.00'): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_mode' => PolicyChargeMode::FixedAmount,
            'amount' => $amount,
        ]);
    }
}
