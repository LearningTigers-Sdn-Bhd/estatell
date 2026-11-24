<?php

namespace Database\Factories;

use App\Models\PosStaffPin;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\PosPinHasher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosStaffPin>
 */
class PosStaffPinFactory extends Factory
{
    /**
     * A 6-digit PIN, `123456` unless withPin() says otherwise.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'user_id' => fn (array $attributes): int => User::factory()->create(['property_id' => $attributes['property_id']])->id,
            'pin_hash' => app(PosPinHasher::class)->make('123456'),
            'pin_length' => 6,
            'active' => true,
        ];
    }

    public function withPin(string $pin): static
    {
        return $this->state([
            'pin_hash' => app(PosPinHasher::class)->make($pin),
            'pin_length' => strlen($pin),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
