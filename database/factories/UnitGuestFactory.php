<?php

namespace Database\Factories;

use App\Models\Guest;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitGuest>
 */
class UnitGuestFactory extends Factory
{
    /**
     * Define the model's default state: a guest of the unit's property, not primary.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_unit_id' => ReservationUnit::factory(),
            'guest_id' => fn (array $attributes): int => Guest::factory()->create([
                'property_id' => ReservationUnit::query()->findOrFail($attributes['reservation_unit_id'])->property_id,
            ])->id,
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => ['is_primary' => true]);
    }
}
