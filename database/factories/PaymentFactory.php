<?php

namespace Database\Factories;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state: a Completed Settlement payment of 100.00 with its payment line on an open
     * folio, a receipt number and no surcharge. The property comes from the folio.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folio_id' => Folio::factory(),
            'property_id' => fn (array $attributes): int => Folio::query()->findOrFail((int) $attributes['folio_id'])->property_id,
            'payment_method_id' => fn (array $attributes): int => PaymentMethod::factory()->create(['property_id' => $attributes['property_id']])->id,
            'amount' => '100.00',
            'purpose' => PaymentPurpose::Settlement,
            'status' => PaymentStatus::Completed,
            'reference' => null,
            'refund_of_id' => null,
            'receipt_number' => fn (): string => 'R-'.fake()->unique()->numerify('######'),
            'folio_line_id' => fn (array $attributes): int => FolioLine::factory()->payment($attributes['amount'])->create([
                'folio_id' => $attributes['folio_id'],
                'transaction_code_id' => PaymentMethod::query()->findOrFail((int) $attributes['payment_method_id'])->transaction_code_id,
            ])->id,
            'surcharge_line_id' => null,
            'received_by' => fn (array $attributes): int => User::factory()->create(['property_id' => $attributes['property_id']])->id,
            'received_at' => now(),
        ];
    }
}
