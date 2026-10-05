<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\TermsDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TermsDocument>
 */
class TermsDocumentFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(['name' => 'Standard', 'is_default' => true]);
    }
}
