<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A menu item. Every item has at least one variant, which holds the price. Order lines copy names, prices and taxes at order time.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_category_id
 * @property int|null $transaction_code_id
 * @property int|null $meal_period_id
 * @property string $name
 * @property string|null $internal_name
 * @property string|null $description
 * @property string|null $sku_prefix
 * @property bool $price_includes_tax
 * @property bool $service_charge_exempt
 * @property int|null $shared_stock_count
 * @property int|null $daily_shared_stock_reset
 * @property string|null $cooking_guide
 * @property bool $sold_by_weight
 * @property string|null $weight_unit
 * @property string|null $weight_unit_divisor
 * @property bool $is_combo
 * @property int $sort_order
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['pos_category_id', 'transaction_code_id', 'meal_period_id', 'name', 'internal_name', 'description', 'sku_prefix', 'price_includes_tax', 'service_charge_exempt', 'shared_stock_count', 'daily_shared_stock_reset', 'cooking_guide', 'sold_by_weight', 'weight_unit', 'weight_unit_divisor', 'is_combo', 'sort_order', 'active'])]
class PosItem extends Model
{
    /** @use HasFactory<PosItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_includes_tax' => 'boolean',
            'service_charge_exempt' => 'boolean',
            'sold_by_weight' => 'boolean',
            'is_combo' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PosComboSlot, $this>
     */
    public function comboSlots(): HasMany
    {
        return $this->hasMany(PosComboSlot::class, 'pos_item_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<PosCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PosCategory::class, 'pos_category_id');
    }

    /**
     * @return BelongsTo<TransactionCode, $this>
     */
    public function transactionCode(): BelongsTo
    {
        return $this->belongsTo(TransactionCode::class, 'transaction_code_id');
    }

    /**
     * @return BelongsTo<MealPeriod, $this>
     */
    public function mealPeriod(): BelongsTo
    {
        return $this->belongsTo(MealPeriod::class, 'meal_period_id');
    }

    /**
     * @return HasMany<PosItemVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(PosItemVariant::class, 'pos_item_id');
    }

    /**
     * @return BelongsToMany<PosModifierGroup, $this>
     */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(PosModifierGroup::class, 'pos_item_modifier_group', 'pos_item_id', 'pos_modifier_group_id')->withPivot('sort_order');
    }
}
