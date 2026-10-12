<?php

namespace App\Models;

use App\Enums\ChargingUnit;
use Database\Factories\ChargeItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A property-configured product or service that staff may post as a charge.
 *
 * @property int $id
 * @property int $property_id
 * @property int $transaction_code_id
 * @property string $name
 * @property string|null $description
 * @property string|null $default_amount
 * @property ChargingUnit $charging_unit
 * @property bool $allow_override
 * @property bool $auto_add
 * @property bool $needs_child_price_review
 * @property int $position
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'transaction_code_id', 'name', 'description', 'default_amount', 'charging_unit',
    'allow_override', 'auto_add', 'needs_child_price_review', 'position', 'active',
])]
class ChargeItem extends Model
{
    /** @use HasFactory<ChargeItemFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'default_amount' => 'decimal:2',
            'charging_unit' => ChargingUnit::class,
            'allow_override' => 'boolean',
            'auto_add' => 'boolean',
            'needs_child_price_review' => 'boolean',
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

    /** @return HasMany<ChargeItemChildPrice, $this> */
    public function childPrices(): HasMany
    {
        return $this->hasMany(ChargeItemChildPrice::class);
    }

    /** @return HasMany<PaymentMethod, $this> */
    public function surchargePaymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class, 'surcharge_charge_item_id');
    }

    /** @param Builder<ChargeItem> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** @param Builder<ChargeItem> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
