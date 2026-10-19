<?php

namespace Database\Factories;

use App\Models\Folio;
use App\Models\FolioManualBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FolioManualBatch>
 */
class FolioManualBatchFactory extends Factory
{
    /**
     * Define the model's default state: a batch on a new folio, by a user of its property.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => fn (array $attributes): int => Folio::factory()->create()->property_id,
            'folio_id' => fn (array $attributes): int => Folio::factory()->create(['property_id' => $attributes['property_id']])->id,
            'user_id' => fn (array $attributes): int => User::factory()->create(['property_id' => $attributes['property_id']])->id,
            'reason' => fake()->sentence(),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
