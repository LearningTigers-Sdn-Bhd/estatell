<?php

namespace Database\Factories;

use App\Models\PosOrderFolioLink;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosOrderFolioLink>
 */
class PosOrderFolioLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'net_amount' => '0.00',
            'tax_amount' => '0.00',
            'gross_amount' => '0.00',
        ];
    }
}
