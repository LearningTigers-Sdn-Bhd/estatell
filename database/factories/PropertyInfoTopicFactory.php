<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyInfoTopic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyInfoTopic>
 */
class PropertyInfoTopicFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'title' => 'Laundry',
            'body' => 'Self-service laundry is on level 2, open 8 am to 10 pm.',
            'position' => 0,
        ];
    }
}
