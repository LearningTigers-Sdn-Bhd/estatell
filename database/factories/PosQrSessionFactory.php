<?php

namespace Database\Factories;

use App\Enums\PosQrSessionKind;
use App\Models\PosQrSession;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PosQrSession>
 */
class PosQrSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'kind' => PosQrSessionKind::Table,
            'token' => Str::random(32),
            'single_use' => true,
            'failed_pin_attempts' => 0,
            'expires_at' => now()->addHours(3),
        ];
    }
}
