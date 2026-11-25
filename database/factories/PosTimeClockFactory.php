<?php

namespace Database\Factories;

use App\Models\PosOutlet;
use App\Models\PosTimeClock;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosTimeClock>
 */
class PosTimeClockFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_outlet_id' => fn (array $attributes): int => PosOutlet::factory()->create(['property_id' => $attributes['property_id']])->id,
            'user_id' => fn (array $attributes): int => User::factory()->create(['property_id' => $attributes['property_id']])->id,
            'clocked_in_at' => now(),
            'clocked_out_at' => null,
        ];
    }
}
