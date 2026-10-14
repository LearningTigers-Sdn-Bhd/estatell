<?php

namespace Database\Factories;

use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArAllocation>
 */
class ArAllocationFactory extends Factory
{
    /**
     * Define the model's default state: an active 100.00 allocation of a payment to an invoice of the same company.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ar_payment_id' => ArPayment::factory(),
            'ar_invoice_id' => fn (array $attributes): int => ArInvoice::factory()->create([
                'property_id' => ArPayment::query()->findOrFail((int) $attributes['ar_payment_id'])->property_id,
                'company_id' => ArPayment::query()->findOrFail((int) $attributes['ar_payment_id'])->company_id,
            ])->id,
            'amount' => '100.00',
            'created_by' => fn (array $attributes): int => User::factory()->create([
                'property_id' => ArPayment::query()->findOrFail((int) $attributes['ar_payment_id'])->property_id,
            ])->id,
        ];
    }

    public function reversed(string $reason = 'Wrong invoice'): static
    {
        return $this->state(fn (array $attributes) => [
            'reversed_at' => now(),
            'reversed_by' => $attributes['created_by'],
            'reversal_reason' => $reason,
        ]);
    }
}
