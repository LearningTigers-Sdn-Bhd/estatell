<?php

namespace Database\Factories;

use App\Enums\TaxRateType;
use App\Models\PosOrderLineTax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosOrderLineTax>
 */
class PosOrderLineTaxFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tax_code' => 'SST',
            'tax_name' => 'SST',
            'rate_type' => TaxRateType::Percent,
            'tax_rate' => '6.00',
            'is_inclusive' => false,
            'taxable_base' => '0.00',
            'tax_amount' => '0.00',
        ];
    }
}
