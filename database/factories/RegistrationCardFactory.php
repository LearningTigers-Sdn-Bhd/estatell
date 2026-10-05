<?php

namespace Database\Factories;

use App\Enums\RegistrationCardStatus;
use App\Models\RegistrationCard;
use App\Models\UnitGuest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationCard>
 */
class RegistrationCardFactory extends Factory
{
    /**
     * Define the model's default state: a draft card of a unit guest.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_guest_id' => UnitGuest::factory(),
            'property_id' => fn (array $attributes): int => UnitGuest::query()->findOrFail($attributes['unit_guest_id'])->reservationUnit->property_id,
            'status' => RegistrationCardStatus::Draft,
        ];
    }
}
