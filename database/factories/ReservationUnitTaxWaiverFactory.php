<?php

namespace Database\Factories;

use App\Enums\TaxWaiverReason;
use App\Models\ReservationUnit;
use App\Models\ReservationUnitTaxWaiver;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationUnitTaxWaiver>
 */
class ReservationUnitTaxWaiverFactory extends Factory
{
    /**
     * Define the model's default state: a tax of the unit's property, paid to an OTA.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_unit_id' => ReservationUnit::factory(),
            'property_id' => fn (array $attributes): int => ReservationUnit::query()->whereKey($attributes['reservation_unit_id'])->firstOrFail()->property_id,
            'tax_id' => fn (array $attributes): int => Tax::factory()->create(['property_id' => $attributes['property_id']])->id,
            'reason' => TaxWaiverReason::PaidElsewhere,
            'paid_to' => 'Trip.com',
            'reference' => (string) fake()->numerify('11281521########'),
            'user_id' => fn (array $attributes): int => User::factory()->create(['property_id' => $attributes['property_id']])->id,
        ];
    }

    /**
     * The guest showed a receipt.
     */
    public function guestReceipt(): static
    {
        return $this->state(fn (): array => [
            'reason' => TaxWaiverReason::GuestReceipt,
            'paid_to' => null,
            'reference' => 'TTX-'.fake()->numerify('#####'),
        ]);
    }
}
