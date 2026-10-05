<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\RegistrationCardTerms;
use App\Models\TermsDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationCardTerms>
 */
class RegistrationCardTermsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'terms_document_id' => TermsDocument::factory(),
            'locale' => Locale::English,
            'version' => 1,
            'body' => fake()->paragraph(),
            'preset' => null,
            'preset_version' => null,
            'published_at' => now(),
            'published_by' => null,
        ];
    }
}
