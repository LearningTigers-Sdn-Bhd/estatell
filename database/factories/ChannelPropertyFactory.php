<?php

namespace Database\Factories;

use App\Enums\ChannelPropertyStatus;
use App\Enums\ChannelProvider;
use App\Models\ChannelProperty;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelProperty>
 */
class ChannelPropertyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'provider' => ChannelProvider::Channex,
            'external_property_id' => fake()->unique()->uuid(),
            'status' => ChannelPropertyStatus::Synced,
            'structure_synced_at' => now(),
        ];
    }
}
