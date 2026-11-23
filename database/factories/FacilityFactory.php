<?php

namespace Database\Factories;

use App\Enums\FacilityBookedBy;
use App\Enums\FacilityRateBasis;
use App\Enums\TransactionCategory;
use App\Models\ChargeItem;
use App\Models\Facility;
use App\Models\Property;
use App\Models\TransactionCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Facility>
 */
class FacilityFactory extends Factory
{
    /**
     * One hall (booked by item, 1 of it) with its own Facility charge item of the same name.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Hall '.fake()->unique()->numberBetween(100, 99999),
            'description' => null,
            'booked_by' => FacilityBookedBy::Item,
            'capacity' => 1,
            'charge_item_id' => fn (array $attributes) => ChargeItem::factory()->create([
                'property_id' => $attributes['property_id'],
                'name' => $attributes['name'],
                'default_amount' => '1500.00',
                'transaction_code_id' => TransactionCode::factory()->create([
                    'property_id' => $attributes['property_id'],
                    'name' => $attributes['name'],
                    'category' => TransactionCategory::Facility,
                ])->id,
            ])->id,
            'rate_basis' => FacilityRateBasis::Day,
            'position' => 1,
            'active' => true,
        ];
    }

    /** An activity booked by person: 20 places, priced per person. */
    public function byPerson(): static
    {
        return $this->state(fn (array $attributes) => [
            'booked_by' => FacilityBookedBy::Person,
            'capacity' => 20,
            'rate_basis' => FacilityRateBasis::Each,
        ]);
    }
}
