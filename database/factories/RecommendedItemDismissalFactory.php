<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\RecommendedItemDismissal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecommendedItemDismissal>
 */
class RecommendedItemDismissalFactory extends Factory
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
            'item' => 'room_groups',
            'dismissed_by' => User::factory(),
            'dismissed_at' => now(),
        ];
    }
}
