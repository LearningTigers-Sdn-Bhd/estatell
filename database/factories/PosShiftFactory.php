<?php

namespace Database\Factories;

use App\Enums\PosShiftStatus;
use App\Models\PosOutlet;
use App\Models\PosRegister;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosShift>
 */
class PosShiftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_outlet_id' => fn (array $attributes): int => PosOutlet::factory()->create(['property_id' => $attributes['property_id']])->id,
            'pos_register_id' => fn (array $attributes): int => PosRegister::factory()->create(['property_id' => $attributes['property_id'], 'pos_outlet_id' => $attributes['pos_outlet_id']])->id,
            'opened_business_date' => '2026-10-14',
            'shift_number' => 'SH-'.fake()->unique()->numerify('########'),
            'status' => PosShiftStatus::Open,
            'opened_by_user_id' => fn (array $attributes): int => User::factory()->create(['property_id' => $attributes['property_id']])->id,
            'opening_cash' => '200.00',
            'opened_at' => now(),
        ];
    }

    public function closed(string $businessDate = '2026-10-14'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PosShiftStatus::Closed,
            'closed_business_date' => $businessDate,
            'closed_at' => now(),
            'closed_by_user_id' => $attributes['opened_by_user_id'],
            'counted_cash' => $attributes['opening_cash'],
            'expected_cash' => $attributes['opening_cash'],
            'cash_variance' => '0.00',
        ]);
    }

    public function closingControl(): static
    {
        return $this->state(['status' => PosShiftStatus::ClosingControl]);
    }
}
