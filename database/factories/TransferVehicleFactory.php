<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\TransferVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferVehicle>
 */
class TransferVehicleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Van '.fake()->unique()->numberBetween(1, 99999),
            'plate' => null,
            'seats' => 10,
            'active' => true,
        ];
    }
}
