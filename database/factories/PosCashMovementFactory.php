<?php

namespace Database\Factories;

use App\Enums\PosCashMovementKind;
use App\Models\PosCashMovement;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PosCashMovement>
 */
class PosCashMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'kind' => PosCashMovementKind::FloatIn,
            'amount' => '10.00',
            'reason' => 'Float',
        ];
    }
}
