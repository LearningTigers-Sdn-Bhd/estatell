<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\FacilitySession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacilitySession>
 */
class FacilitySessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => Facility::factory()->shared(),
            'property_id' => fn (array $attributes) => Facility::query()->whereKey($attributes['facility_id'])->value('property_id'),
            'starts_at' => '20:00',
            'ends_at' => '22:00',
        ];
    }
}
