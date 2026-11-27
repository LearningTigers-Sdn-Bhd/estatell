<?php

namespace Database\Factories;

use App\Models\PropertyGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyGroup>
 */
class PropertyGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
        ];
    }
}
