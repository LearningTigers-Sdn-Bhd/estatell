<?php

namespace Database\Factories;

use App\Models\UnitAddOn;
use App\Models\UnitTransfer;
use App\Models\UnitTransferAddOn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitTransferAddOn>
 */
class UnitTransferAddOnFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_transfer_id' => UnitTransfer::factory(),
            'unit_add_on_id' => UnitAddOn::factory(),
        ];
    }
}
