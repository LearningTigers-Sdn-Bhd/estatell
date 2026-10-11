<?php

namespace Database\Factories;

use App\Models\Rate;
use App\Models\RateHourPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RateHourPrice>
 */
class RateHourPriceFactory extends Factory
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
            'hours' => 4,
            'amount' => fake()->numberBetween(40, 200).'.00',
        ];
    }
}
