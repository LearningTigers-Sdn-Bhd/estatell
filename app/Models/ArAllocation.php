<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ArAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part of an AR payment matched to an invoice. Never edited: a mistake is reversed, which sets `reversed_at`,
 * `reversed_by` and `reversal_reason` once. Only active (not reversed) allocations count toward a balance.
 * See docs/15-city-ledger/03-ar-payments.md.
 *
 * @property int $id
 * @property int $ar_payment_id
 * @property int $ar_invoice_id
 * @property string $amount
 * @property int $created_by
 * @property CarbonImmutable|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reversal_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['ar_payment_id', 'ar_invoice_id', 'amount', 'created_by', 'reversed_at', 'reversed_by', 'reversal_reason'])]
class ArAllocation extends Model
{
    /** @use HasFactory<ArAllocationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ArPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(ArPayment::class, 'ar_payment_id');
    }

    /** @return BelongsTo<ArInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id');
    }

    /**
     * Allocations that are not reversed.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('reversed_at');
    }
}
