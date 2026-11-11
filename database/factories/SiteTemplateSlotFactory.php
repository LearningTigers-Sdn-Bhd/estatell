<?php

namespace Database\Factories;

use App\Models\SiteTemplate;
use App\Models\SiteTemplateSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteTemplateSlot>
 */
class SiteTemplateSlotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_template_id' => SiteTemplate::factory(),
            'key' => fake()->unique()->slug(1),
            'label' => fake()->words(2, true),
            'required' => false,
            'position' => fn (array $attributes): int => ((int) SiteTemplateSlot::query()->where('site_template_id', $attributes['site_template_id'])->max('position')) + 1,
        ];
    }

    public function required(): static
    {
        return $this->state(['required' => true]);
    }
}
