<?php

namespace App\Models;

use App\Enums\PosShiftStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PosShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One cash-control session on a register, from opening float to the blind-counted close. Totals are copied from the payments when the count starts and frozen into the Z-report at close.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property int $pos_register_id
 * @property CarbonImmutable $opened_business_date
 * @property CarbonImmutable|null $closed_business_date
 * @property string $shift_number
 * @property PosShiftStatus $status
 * @property int $opened_by_user_id
 * @property int|null $closed_by_user_id
 * @property string $opening_cash
 * @property string $cash_payments
 * @property string $cash_refunds
 * @property string $paid_in
 * @property string $paid_out
 * @property string|null $expected_cash
 * @property string|null $counted_cash
 * @property int $recount_count
 * @property string|null $cash_variance
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closing_note
 * @property array<string, mixed>|null $z_report_snapshot
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['opening_cash', 'closing_note'])]
class PosShift extends Model
{
    /** @use HasFactory<PosShiftFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_business_date' => 'date',
            'closed_business_date' => 'date',
            'status' => PosShiftStatus::class,
            'opening_cash' => 'decimal:2',
            'cash_payments' => 'decimal:2',
            'cash_refunds' => 'decimal:2',
            'paid_in' => 'decimal:2',
            'paid_out' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'cash_variance' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'z_report_snapshot' => 'array',
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
     * @return BelongsTo<PosOutlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }

    /**
     * @return BelongsTo<PosRegister, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * @return HasMany<PosCashMovement, $this>
     */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class, 'pos_shift_id');
    }

    /**
     * @return HasMany<PosOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(PosOrder::class, 'pos_shift_id');
    }

    /**
     * @return HasMany<PosOrderPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PosOrderPayment::class, 'pos_shift_id');
    }
}
