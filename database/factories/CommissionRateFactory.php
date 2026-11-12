<?php

namespace Database\Factories;

use App\Enums\CommissionService;
use App\Models\CommissionRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionRate>
 */
class CommissionRateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service' => CommissionService::BookingEngine,
            'rate' => '8.00',
            'effective_from' => '2026-12-01',
        ];
    }
}
