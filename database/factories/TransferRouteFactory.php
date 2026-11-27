<?php

namespace Database\Factories;

use App\Enums\TransferDirection;
use App\Models\Property;
use App\Models\TransferRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An In route with no prices by default.
 *
 * @extends Factory<TransferRoute>
 */
class TransferRouteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'direction' => TransferDirection::In,
            'name' => 'Route '.fake()->unique()->numberBetween(1000, 99999),
            'place' => 'Sandakan Airport',
            'minutes' => 40,
            'shared_charge_item_id' => null,
            'private_charge_item_id' => null,
            'active' => true,
        ];
    }

    public function out(): static
    {
        return $this->state(fn (array $attributes) => ['direction' => TransferDirection::Out]);
    }
}
