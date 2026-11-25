<?php

namespace App\Models;

use App\Enums\PosDiscountType;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use Carbon\CarbonImmutable;
use Database\Factories\PosOrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ordered item variant with its price, discount, service charge and tax at order time. Refund lines point at the line they refund.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_order_id
 * @property int|null $pos_item_variant_id
 * @property PosOrderLineKind $line_kind
 * @property bool $price_includes_tax
 * @property bool $service_charge_exempt
 * @property int|null $parent_combo_line_id
 * @property int|null $kitchen_station_id
 * @property int|null $refund_of_order_line_id
 * @property int $transaction_code_id
 * @property bool $is_entitlement_covered
 * @property int|null $meal_redemption_id
 * @property string $item_name
 * @property string $variant_name
 * @property string|null $internal_name
 * @property string $quantity
 * @property string $unit_price
 * @property string|null $unit_cost
 * @property string $line_gross
 * @property PosDiscountType|null $discount_type
 * @property string|null $discount_value
 * @property string $discount_amount
 * @property bool $exclude_addons_from_discount
 * @property string|null $discount_reason
 * @property int|null $discount_approved_by_user_id
 * @property string $net_amount
 * @property string $service_charge_amount
 * @property string $tax_amount
 * @property string $line_total
 * @property int $course_number
 * @property int|null $seat_number
 * @property PosKitchenStatus $kitchen_status
 * @property CarbonImmutable|null $fired_at
 * @property CarbonImmutable|null $preparing_at
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $served_at
 * @property string|null $note
 * @property int|null $void_reason_id
 * @property string|null $void_reason_text
 * @property int|null $voided_by_user_id
 * @property int|null $void_approved_by_user_id
 * @property bool $return_stock_on_void
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['note'])]
class PosOrderLine extends Model
{
    /** @use HasFactory<PosOrderLineFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_kind' => PosOrderLineKind::class,
            'price_includes_tax' => 'boolean',
            'service_charge_exempt' => 'boolean',
            'is_entitlement_covered' => 'boolean',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'line_gross' => 'decimal:2',
            'discount_type' => PosDiscountType::class,
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'exclude_addons_from_discount' => 'boolean',
            'net_amount' => 'decimal:2',
            'service_charge_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'kitchen_status' => PosKitchenStatus::class,
            'fired_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
            'return_stock_on_void' => 'boolean',
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

    /**
     * @return BelongsTo<PosItemVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(PosItemVariant::class, 'pos_item_variant_id');
    }

    /**
     * @return BelongsTo<PosKitchenStation, $this>
     */
    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(PosKitchenStation::class, 'kitchen_station_id');
    }

    /**
     * @return BelongsTo<PosOrderLine, $this>
     */
    public function parentComboLine(): BelongsTo
    {
        return $this->belongsTo(PosOrderLine::class, 'parent_combo_line_id');
    }

    /**
     * @return BelongsTo<MealRedemption, $this>
     */
    public function mealRedemption(): BelongsTo
    {
        return $this->belongsTo(MealRedemption::class, 'meal_redemption_id');
    }

    /**
     * @return BelongsTo<TransactionCode, $this>
     */
    public function transactionCode(): BelongsTo
    {
        return $this->belongsTo(TransactionCode::class, 'transaction_code_id');
    }

    /**
     * @return BelongsTo<PosOrderLine, $this>
     */
    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(PosOrderLine::class, 'refund_of_order_line_id');
    }

    /**
     * @return HasMany<PosOrderLineModifier, $this>
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(PosOrderLineModifier::class, 'pos_order_line_id');
    }

    /**
     * @return HasMany<PosOrderLineTax, $this>
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(PosOrderLineTax::class, 'pos_order_line_id');
    }
}
