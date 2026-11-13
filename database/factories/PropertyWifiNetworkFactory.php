<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyWifiNetwork;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyWifiNetwork>
 */
class PropertyWifiNetworkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Guest Wi-Fi',
            'password' => 'welcome2026',
            'note' => 'Lobby and rooms',
            'position' => 0,
        ];
    }
}
