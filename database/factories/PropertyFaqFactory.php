<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyFaq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyFaq>
 */
class PropertyFaqFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'question' => 'Is breakfast included?',
            'answer' => 'Breakfast is served from 7 to 10 am.',
            'position' => 0,
        ];
    }
}
