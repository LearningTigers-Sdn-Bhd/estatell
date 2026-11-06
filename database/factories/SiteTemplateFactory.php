<?php

namespace Database\Factories;

use App\Enums\SiteTemplateStatus;
use App\Enums\SiteTemplateType;
use App\Models\Property;
use App\Models\SiteTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteTemplate>
 */
class SiteTemplateFactory extends Factory
{
    /**
     * An Available Standard template.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->unique()->words(2, true),
            'type' => SiteTemplateType::Standard,
            'property_id' => null,
            'status' => SiteTemplateStatus::Available,
            'allows_main_color' => true,
            'preview_url' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => SiteTemplateStatus::Draft]);
    }

    public function retired(): static
    {
        return $this->state(['status' => SiteTemplateStatus::Retired]);
    }

    public function custom(?Property $property = null): static
    {
        return $this->state([
            'type' => SiteTemplateType::Custom,
            'property_id' => $property === null ? Property::factory() : $property->id,
        ]);
    }
}
