<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyMembership>
 */
class PropertyMembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'user_id' => User::factory(),
            'deactivated_at' => null,
        ];
    }
}
