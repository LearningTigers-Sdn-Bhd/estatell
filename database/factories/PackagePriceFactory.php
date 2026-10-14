<?php

namespace Database\Factories;

use App\Models\PackagePrice;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackagePrice>
 */
class PackagePriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rate_plan_id' => RatePlan::factory()->package(),
            'room_type_id' => fn (array $attributes) => RoomType::factory()->create([
                'property_id' => RatePlan::query()->findOrFail((int) $attributes['rate_plan_id'])->property_id,
            ])->id,
            'arrival_from' => '2026-06-01',
            'arrival_to' => '2026-06-30',
            'amount' => '600.00',
        ];
    }
}
