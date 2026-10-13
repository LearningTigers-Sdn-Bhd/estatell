<?php

namespace Database\Factories;

use App\Actions\Property\GenerateDocumentPrefix;
use App\Actions\Property\IssuePropertyCode;
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
            'code' => fn () => app(IssuePropertyCode::class)(),
            'name' => fake()->company().' Inn',
            'type' => PropertyType::Hotel,
            'status' => PropertyStatus::Live,
            'city' => fake()->city(),
            'country_code' => 'MY',
            'onboarding_started_at' => now(),
            'went_live_at' => now(),
        ];
    }

    /**
     * Issue the document prefix and load the row after it is inserted. Every property has its
     * print settings row, as the back-fill and SeedPropertyDefaults give it.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Property $property): void {
            app(GenerateDocumentPrefix::class)($property);
            $property->printSetting()->firstOrCreate();
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

    public function onboarding(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PropertyStatus::Onboarding,
            'went_live_at' => null,
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
