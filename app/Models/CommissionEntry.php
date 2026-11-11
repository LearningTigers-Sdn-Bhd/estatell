<?php

namespace App\Models;

use App\Enums\CommissionEntryType;
use App\Enums\CommissionService;
use Carbon\CarbonImmutable;
use Database\Factories\CommissionEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One line of the commission ledger. Never updated or deleted: a correction is a reversal entry.
 * The amount is signed from the property's side (+ owed to the property, − owed to Estatell).
 * See docs/30-commission-fees/03-ledger.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $reservation_id
 * @property CommissionEntryType $type
 * @property CommissionService|null $service
 * @property string|null $base_amount
 * @property string|null $rate
 * @property string $amount
 * @property CarbonImmutable|null $due_date
 * @property int|null $gateway_payment_id
 * @property int|null $gateway_refund_id
 * @property int|null $reverses_entry_id
 * @property string|null $idempotency_key
 * @property string|null $reason
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['property_id', 'reservation_id', 'type', 'service', 'base_amount', 'rate', 'amount', 'due_date', 'gateway_payment_id', 'gateway_refund_id', 'reverses_entry_id', 'idempotency_key', 'reason', 'created_by'])]
class CommissionEntry extends Model
{
    /** @use HasFactory<CommissionEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CommissionEntryType::class,
            'service' => CommissionService::class,
            'base_amount' => 'decimal:2',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'due_date' => 'immutable_date',
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
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<GatewayPayment, $this>
     */
    public function gatewayPayment(): BelongsTo
    {
        return $this->belongsTo(GatewayPayment::class);
    }

    /**
     * @return BelongsTo<GatewayRefund, $this>
     */
    public function gatewayRefund(): BelongsTo
    {
        return $this->belongsTo(GatewayRefund::class);
    }

    /**
     * The entry this one reverses.
     *
     * @return BelongsTo<CommissionEntry, $this>
     */
    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /**
     * The entry that reverses this one, if any.
     *
     * @return HasOne<CommissionEntry, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
