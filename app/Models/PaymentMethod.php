<?php

namespace App\Models;

use App\Enums\PaymentMethodType;
use App\Enums\SurchargeType;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One property-configured way to settle a folio or classify a collected payment.
 *
 * @property int $id
 * @property int $property_id
 * @property int $transaction_code_id
 * @property string $name
 * @property PaymentMethodType $type
 * @property SurchargeType $surcharge_type
 * @property string|null $surcharge_value
 * @property int|null $surcharge_charge_item_id
 * @property bool $requires_reference
 * @property int $position
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'transaction_code_id', 'name', 'type', 'surcharge_type', 'surcharge_value',
    'surcharge_charge_item_id', 'requires_reference', 'position', 'active',
])]
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'surcharge_type' => SurchargeType::class,
            'surcharge_value' => 'decimal:2',
            'requires_reference' => 'boolean',
            'position' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<TransactionCode, $this> */
    public function transactionCode(): BelongsTo
    {
        return $this->belongsTo(TransactionCode::class);
    }

    /** @return BelongsTo<ChargeItem, $this> */
    public function surchargeChargeItem(): BelongsTo
    {
        return $this->belongsTo(ChargeItem::class, 'surcharge_charge_item_id');
    }

    /** @param Builder<PaymentMethod> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** @param Builder<PaymentMethod> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
