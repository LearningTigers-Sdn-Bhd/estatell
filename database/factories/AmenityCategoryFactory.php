<?php

namespace Database\Factories;

use App\Enums\AmenityType;
use App\Models\AmenityCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AmenityCategory>
 */
class AmenityCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => AmenityType::Property,
            'name' => fake()->unique()->words(2, true),
            'position' => fn (): int => ((int) AmenityCategory::query()->max('position')) + 1,
        ];
    }

    public function room(): static
    {
        return $this->state(fn (array $attributes) => ['type' => AmenityType::Room]);
    }
}
