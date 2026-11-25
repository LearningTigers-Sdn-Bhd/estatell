<?php

namespace App\Models;

use App\Enums\PosDiscountType;
use Carbon\CarbonImmutable;
use Database\Factories\PosOrderDiscountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A discount on the whole bill, taken off before service charge and tax.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_order_id
 * @property int|null $discount_id
 * @property string $name
 * @property PosDiscountType $discount_type
 * @property string $value
 * @property string $amount
 * @property string|null $reason
 * @property int $applied_by_user_id
 * @property int|null $approved_by_user_id
 * @property CarbonImmutable $created_at
 */
class PosOrderDiscount extends Model
{
    /** @use HasFactory<PosOrderDiscountFactory> */
    use HasFactory;

    /** The row is written once and never edited, so it has only a creation time. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => PosDiscountType::class,
            'value' => 'decimal:2',
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
     * @return BelongsTo<PosOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }
}
