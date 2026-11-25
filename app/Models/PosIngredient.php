<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosIngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock item of the back of house, counted in its base unit. Its cost is a moving average per base unit.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string|null $sku
 * @property string $base_unit
 * @property string|null $purchase_unit
 * @property string $purchase_to_base_ratio
 * @property string $unit_cost
 * @property string|null $low_stock_threshold
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'sku', 'base_unit', 'purchase_unit', 'purchase_to_base_ratio', 'unit_cost', 'low_stock_threshold', 'active'])]
class PosIngredient extends Model
{
    /** @use HasFactory<PosIngredientFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchase_to_base_ratio' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'low_stock_threshold' => 'decimal:3',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PosStockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(PosStockMovement::class, 'pos_ingredient_id');
    }

    /**
     * @return HasMany<PosRecipe, $this>
     */
    public function recipes(): HasMany
    {
        return $this->hasMany(PosRecipe::class, 'pos_ingredient_id');
    }
}
