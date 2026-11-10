<?php

namespace Database\Factories;

use App\Enums\BoatDirection;
use App\Enums\SailingKind;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A departure sailing on 20 Oct 2026 by default. Pass `boat_departure_id` of a departure of the same property.
 *
 * @extends Factory<BoatSailing>
 */
class BoatSailingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'date' => '2026-10-20',
            'kind' => SailingKind::Guests,
            'direction' => BoatDirection::In,
            'boat_departure_id' => BoatDeparture::factory(),
            'leaves_at' => null,
            'arrives_at' => null,
            'boat_id' => null,
        ];
    }

    public function forDeparture(BoatDeparture $departure): static
    {
        return $this->state(fn (array $attributes) => [
            'property_id' => $departure->property_id,
            'boat_departure_id' => $departure->id,
            'direction' => $departure->direction,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => ['cancelled_at' => now()]);
    }
}
