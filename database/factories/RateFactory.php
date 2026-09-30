<?php

namespace Database\Factories;

use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rate>
 */
class RateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_type_id' => RoomType::factory(),
            'rate_plan_id' => RatePlan::factory(),
            'date' => now()->addDay()->toDateString(),
            'amount' => fake()->numberBetween(80, 400).'.00',
        ];
    }

    public function stopSell(): static
    {
        return $this->state(fn (array $attributes) => ['stop_sell' => true]);
    }
}
