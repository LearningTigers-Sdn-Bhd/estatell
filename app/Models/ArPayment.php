<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ArPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money a company paid against its city ledger. It is matched to invoices by allocations, and what is not allocated
 * stays as credit on the company. Never edited after it is recorded: only `voided_at` is set, once. Unallocated
 * money is calculated, not stored. See docs/15-city-ledger/03-ar-payments.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int $company_id
 * @property int $payment_method_id
 * @property string $amount
 * @property CarbonImmutable $received_on
 * @property string|null $reference
 * @property string|null $notes
 * @property string|null $receipt_number Null only for money recorded from the old system, which has no receipt here.
 * @property string $idempotency_key
 * @property int $received_by
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'company_id', 'payment_method_id', 'amount', 'received_on', 'reference', 'notes', 'receipt_number',
    'idempotency_key', 'received_by', 'voided_at', 'voided_by',
])]
class ArPayment extends Model
{
    /** @use HasFactory<ArPaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return HasMany<ArAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(ArAllocation::class);
    }

    /**
     * What staff and statements call this payment: the receipt number, or "Old system · {reference}" for money recorded
     * from the old system, which has no receipt here.
     */
    public function displayNumber(): string
    {
        return $this->receipt_number ?? __('Old system · :reference', ['reference' => $this->reference]);
    }

    /**
     * Whether a receipt PDF exists for it. Money recorded from the old system has none.
     */
    public function hasReceipt(): bool
    {
        return $this->receipt_number !== null;
    }
}
