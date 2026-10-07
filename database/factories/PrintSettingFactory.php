<?php

namespace Database\Factories;

use App\Models\PrintSetting;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintSetting>
 */
class PrintSettingFactory extends Factory
{
    /**
     * Define the model's default state. The columns keep their database defaults.
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
