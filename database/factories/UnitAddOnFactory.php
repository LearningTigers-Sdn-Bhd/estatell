<?php

namespace Database\Factories;

use App\Models\ChargeItem;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitAddOn>
 */
class UnitAddOnFactory extends Factory
{
    /**
     * Define the model's default state: one undated add-on of 25.00 on a charge item of the unit's property.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_unit_id' => ReservationUnit::factory(),
            'charge_item_id' => fn (array $attributes): int => ChargeItem::factory()->create([
                'property_id' => ReservationUnit::query()->findOrFail($attributes['reservation_unit_id'])->property_id,
            ])->id,
            'quantity' => 1,
            'unit_amount' => '25.00',
            'service_date' => null,
        ];
    }

    /**
     * An add-on that is already posted: its `folio_line_id` names the given charge line, or a new one.
     */
    public function posted(?FolioLine $line = null): static
    {
        return $this->state(fn (array $attributes) => [
            'folio_line_id' => $line?->id ?? FolioLine::factory()->create()->id,
        ]);
    }
}
