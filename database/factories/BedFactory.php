<?php

namespace Database\Factories;

use App\Enums\SellUnit;
use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bed>
 */
class BedFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => function (): int {
                $property = Property::factory()->create();

                return Room::factory()->create([
                    'property_id' => $property->id,
                    'room_type_id' => RoomType::factory()->create(['property_id' => $property->id, 'sell_unit' => SellUnit::Bed])->id,
                ])->id;
            },
            'label' => 'A'.fake()->unique()->numberBetween(1, 99999),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['archived_at' => now()]);
    }
}
