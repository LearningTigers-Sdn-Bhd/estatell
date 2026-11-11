<?php

namespace Database\Factories;

use App\Enums\CommissionEntryType;
use App\Models\CommissionEntry;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An adjustment by default, because it needs no reservation or gateway row.
 *
 * @extends Factory<CommissionEntry>
 */
class CommissionEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'type' => CommissionEntryType::Adjustment,
            'amount' => '25.00',
            'due_date' => '2026-10-14',
            'reason' => 'Goodwill credit',
            'created_by' => User::factory(),
        ];
    }
}
