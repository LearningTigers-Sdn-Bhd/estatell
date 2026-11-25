<?php

namespace App\Models;

use App\Enums\PosCashMovementKind;
use Carbon\CarbonImmutable;
use Database\Factories\PosCashMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash that went in or out of the drawer outside a sale: float in, safe drop, payout or a no-sale opening. It never changes after it is written.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_shift_id
 * @property CarbonImmutable $business_date
 * @property string $idempotency_key
 * @property PosCashMovementKind $kind
 * @property int|null $counter_transaction_code_id
 * @property string $amount
 * @property string $reason
 * @property int $user_id
 * @property int|null $approved_by_user_id
 * @property CarbonImmutable $created_at
 */
class PosCashMovement extends Model
{
    /** @use HasFactory<PosCashMovementFactory> */
    use HasFactory;

    /** The row is written once and never edited, so it has only a creation time. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'kind' => PosCashMovementKind::class,
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
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
     * @return BelongsTo<PosShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    /**
     * @return BelongsTo<TransactionCode, $this>
     */
    public function counterTransactionCode(): BelongsTo
    {
        return $this->belongsTo(TransactionCode::class, 'counter_transaction_code_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
