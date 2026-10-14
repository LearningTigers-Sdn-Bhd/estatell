<?php

namespace Database\Factories;

use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\TransactionCode;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FolioLine>
 */
class FolioLineFactory extends Factory
{
    /**
     * Define the model's default state: one charge of 100.00 on a transaction code of the folio's property.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folio_id' => Folio::factory(),
            'transaction_code_id' => fn (array $attributes): int => TransactionCode::factory()->create([
                'property_id' => Folio::query()->findOrFail($attributes['folio_id'])->property_id,
            ])->id,
            'kind' => TransactionKind::Charge,
            'description' => 'Room charge',
            'quantity' => 1,
            'unit_amount' => '100.00',
            'amount' => '100.00',
            'posting_date' => '2026-11-02',
            'idempotency_key' => fn (): string => 'test:'.fake()->unique()->uuid(),
        ];
    }

    /**
     * A payment of the given amount. The line amount is negative.
     */
    public function payment(string $amount = '100.00'): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => TransactionKind::Payment,
            'description' => 'Payment',
            'unit_amount' => '-'.$amount,
            'amount' => '-'.$amount,
        ]);
    }

    /**
     * The reversal of another line: the same code and folio with the opposite amount.
     */
    public function reversalOf(FolioLine $line): static
    {
        return $this->state(fn (array $attributes) => [
            'folio_id' => $line->folio_id,
            'transaction_code_id' => $line->transaction_code_id,
            'kind' => $line->kind,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_amount' => (string) BigDecimal::of($line->unit_amount)->negated()->toScale(2),
            'amount' => (string) BigDecimal::of($line->amount)->negated()->toScale(2),
            'reversal_of_id' => $line->id,
            'idempotency_key' => 'reversal:'.$line->id,
        ]);
    }
}
