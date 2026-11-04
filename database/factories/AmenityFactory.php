<?php

namespace Database\Factories;

use App\Enums\AmenityType;
use App\Models\Amenity;
use App\Models\AmenityCategory;
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
            'amenity_category_id' => fn (array $attributes): int => AmenityCategory::query()->firstOrCreate(
                ['type' => $attributes['type'], 'name' => 'General'],
                ['position' => ((int) AmenityCategory::query()->max('position')) + 1],
            )->id,
            'icon' => null,
            'position' => fn (): int => ((int) Amenity::query()->max('position')) + 1,
            'active' => true,
        ];
    }

    public function room(): static
    {
        return $this->state(fn (array $attributes) => ['type' => AmenityType::Room]);
    }

    public function inCategory(AmenityCategory $category): static
    {
        return $this->state(fn (array $attributes) => ['type' => $category->type, 'amenity_category_id' => $category->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
