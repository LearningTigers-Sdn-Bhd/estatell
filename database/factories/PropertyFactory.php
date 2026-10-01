<?php

namespace Database\Factories;

use App\Actions\Property\GenerateDocumentPrefix;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Inn',
            'type' => PropertyType::Hotel,
            'status' => PropertyStatus::Onboarding,
            'city' => fake()->city(),
            'country_code' => 'MY',
            'onboarding_started_at' => now(),
        ];
    }

    /**
     * Issue the document prefix and load the sequence-issued code after the row is inserted.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Property $property): void {
            app(GenerateDocumentPrefix::class)($property);
            $property->refresh();
        });
    }

    public function live(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PropertyStatus::Live,
            'went_live_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PropertyStatus::Suspended,
            'suspended_from_status' => PropertyStatus::Onboarding,
            'suspended_at' => now(),
        ]);
    }
}
