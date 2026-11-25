<?php

namespace Database\Factories;

use App\Enums\AiScope;
use App\Models\Property;
use App\Models\PropertyAiScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyAiScope>
 */
class PropertyAiScopeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'scope' => AiScope::Concierge,
            'allowed' => true,
            'enabled' => true,
        ];
    }
}
