<?php

namespace Database\Factories;

use App\Enums\OtaCredentialStatus;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\PropertyOtaCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyOtaCredential>
 */
class PropertyOtaCredentialFactory extends Factory
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
            'ota_channel_id' => OtaChannel::factory(),
            'channel_name' => null,
            'property_code' => fake()->numerify('######'),
            'username' => fake()->unique()->safeEmail(),
            'password' => 'secret-'.fake()->password(12),
            'market_manager_name' => null,
            'market_manager_phone' => null,
            'market_manager_email' => null,
            'status' => OtaCredentialStatus::Pending,
        ];
    }

    /**
     * A row for the locked Other channel, with the name the owner typed.
     */
    public function other(string $channelName = 'Traveloka'): static
    {
        return $this->state(fn (array $attributes) => [
            'ota_channel_id' => OtaChannel::factory()->other(),
            'channel_name' => $channelName,
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => OtaCredentialStatus::Processed]);
    }
}
