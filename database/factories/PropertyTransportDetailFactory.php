<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyTransportDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyTransportDetail>
 */
class PropertyTransportDetailFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'directions' => 'Turn left at the second light.',
            'airport_distance_km' => 25,
            'airport_travel_minutes' => 35,
        ];
    }
}
