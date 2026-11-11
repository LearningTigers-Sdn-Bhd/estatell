<?php

namespace App\Models;

use App\Enums\PayoutVoucherStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PayoutVoucherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One property's payout for one cycle (16th to 15th, paid on the 1st). `amount` is signed from the property's side:
 * + Estatell pays the property, − the property owes Estatell. See docs/30-commission-fees/04-payout-vouchers.md.
 *
 * @property int $id
 * @property int $property_id
 * @property string $number
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $due_on
 * @property string $amount
 * @property PayoutVoucherStatus $status
 * @property array<string, mixed>|null $snapshot
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $paid_by
 * @property CarbonImmutable|null $paid_on
 * @property string|null $paid_reference
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'number', 'period_start', 'period_end', 'due_on', 'amount', 'status', 'snapshot', 'approved_by', 'approved_at', 'paid_by', 'paid_on', 'paid_reference'])]
class PayoutVoucher extends Model
{
    /** @use HasFactory<PayoutVoucherFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'due_on' => 'immutable_date',
            'amount' => 'decimal:2',
            'status' => PayoutVoucherStatus::class,
            'snapshot' => 'array',
            'approved_at' => 'immutable_datetime',
            'paid_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<CommissionEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(CommissionEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
