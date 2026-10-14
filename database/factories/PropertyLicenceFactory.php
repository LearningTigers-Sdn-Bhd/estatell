<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyLicence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyLicence>
 */
class PropertyLicenceFactory extends Factory
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
            'issuer' => 'Dewan Bandaraya Kota Kinabalu',
            'number' => fake()->unique()->numerify('DBKK-######'),
            'expires_on' => null,
            'show_on_documents' => false,
            'position' => 0,
        ];
    }
}
