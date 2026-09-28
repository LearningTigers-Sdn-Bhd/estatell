<?php

namespace Database\Factories;

use App\Enums\SellUnit;
use App\Models\Property;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomType>
 */
class RoomTypeFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'sell_unit' => SellUnit::Room,
            'max_adults' => 2,
            'max_children' => 1,
            'base_occupancy' => 2,
        ];
    }

    public function bed(): static
    {
        return $this->state(fn (array $attributes) => [
            'sell_unit' => SellUnit::Bed,
            'max_adults' => 1,
            'max_children' => 0,
            'base_occupancy' => 1,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['archived_at' => now()]);
    }
}
