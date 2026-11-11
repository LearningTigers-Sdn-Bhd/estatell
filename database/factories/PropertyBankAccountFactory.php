<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyBankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyBankAccount>
 */
class PropertyBankAccountFactory extends Factory
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
            'bank_name' => fake()->randomElement(['Maybank', 'CIMB Bank', 'RHB Bank', 'Public Bank']),
            'account_name' => fake()->company(),
            'account_number' => fake()->unique()->numerify('#### #### ####'),
            'swift_code' => null,
            'position' => 1,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
