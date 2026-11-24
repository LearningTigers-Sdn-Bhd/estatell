<?php

namespace App\Models;

use App\Enums\PosPricingType;
use Carbon\CarbonImmutable;
use Database\Factories\PosItemVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sellable form of an item (Regular, Large, Iced) with its own price, cost, SKU and barcode.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_item_id
 * @property string $name
 * @property string|null $sku
 * @property string|null $barcode
 * @property PosPricingType $pricing_type
 * @property string $price
 * @property string|null $cost
 * @property int $sort_order
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'sku', 'barcode', 'pricing_type', 'price', 'cost', 'sort_order', 'active'])]
class PosItemVariant extends Model
{
    /** @use HasFactory<PosItemVariantFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pricing_type' => PosPricingType::class,
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'active' => 'boolean',
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
     * @return BelongsTo<PosItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PosItem::class, 'pos_item_id');
    }

    /**
     * @return HasMany<PosOutletItem, $this>
     */
    public function outletItems(): HasMany
    {
        return $this->hasMany(PosOutletItem::class, 'pos_item_variant_id');
    }
}
