<?php

namespace Database\Factories;

use App\Enums\AccountingDocumentKind;
use App\Enums\AccountingDocumentStatus;
use App\Enums\AccountingProvider;
use App\Models\AccountingDocument;
use App\Models\ArInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingDocument>
 */
class AccountingDocumentFactory extends Factory
{
    /**
     * A sent invoice document.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ar_invoice_id' => ArInvoice::factory(),
            'property_id' => fn (array $attributes): int => ArInvoice::query()->findOrFail((int) $attributes['ar_invoice_id'])->property_id,
            'provider' => AccountingProvider::Qne,
            'kind' => AccountingDocumentKind::Invoice,
            'status' => AccountingDocumentStatus::Pushed,
            'external_id' => (string) fake()->unique()->numberBetween(100, 99999),
            'attempts' => 1,
            'pushed_at' => now(),
        ];
    }
}
