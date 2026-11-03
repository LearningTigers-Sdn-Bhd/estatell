<?php

namespace Database\Factories;

use App\Enums\AmenityType;
use App\Models\Amenity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Amenity>
 */
class AmenityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'type' => AmenityType::Property,
            'category' => 'General',
            'icon' => null,
            'position' => fn (): int => ((int) Amenity::query()->max('position')) + 1,
            'active' => true,
        ];
    }

    public function room(): static
    {
        return $this->state(fn (array $attributes) => ['type' => AmenityType::Room]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
