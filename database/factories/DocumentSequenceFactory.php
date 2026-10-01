<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\DocumentSequence;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentSequence>
 */
class DocumentSequenceFactory extends Factory
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
            'type' => DocumentType::Receipt,
            'year' => (int) now()->format('Y'),
            'last_number' => 0,
        ];
    }
}
