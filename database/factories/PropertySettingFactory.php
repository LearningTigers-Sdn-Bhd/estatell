<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertySetting>
 */
class PropertySettingFactory extends Factory
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
        ];
    }
}
