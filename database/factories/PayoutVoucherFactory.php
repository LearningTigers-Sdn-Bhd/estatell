<?php

namespace Database\Factories;

use App\Enums\PayoutVoucherStatus;
use App\Models\PayoutVoucher;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A Draft voucher for the cycle 16 Sep – 15 Oct 2026, due on 2 Nov 2026 (1 Nov is a Sunday).
 *
 * @extends Factory<PayoutVoucher>
 */
class PayoutVoucherFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'number' => fn (array $attributes): string => 'PV-202610-'.Property::query()->findOrFail((int) $attributes['property_id'])->code,
            'period_start' => '2026-09-16',
            'period_end' => '2026-10-15',
            'due_on' => '2026-11-02',
            'amount' => '0.00',
            'status' => PayoutVoucherStatus::Draft,
        ];
    }
}
