<?php

namespace Database\Factories;

use App\Models\Rate;
use App\Models\RateOccupancyPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RateOccupancyPrice>
 */
class RateOccupancyPriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rate_id' => Rate::factory(),
            'adults' => 2,
            'amount' => fake()->numberBetween(80, 400).'.00',
        ];
    }
}
