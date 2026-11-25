<?php

namespace App\Models;

use App\Enums\PosAvailability;
use Carbon\CarbonImmutable;
use Database\Factories\PosOutletItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one outlet does with one variant: a price override, an availability state and a stock countdown.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property int $pos_item_variant_id
 * @property string|null $price_override
 * @property PosAvailability $availability
 * @property int|null $stock_countdown
 * @property int|null $daily_stock_reset
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['price_override', 'availability', 'stock_countdown', 'daily_stock_reset'])]
class PosOutletItem extends Model
{
    /** @use HasFactory<PosOutletItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_override' => 'decimal:2',
            'availability' => PosAvailability::class,
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
     * @return BelongsTo<PosItemVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(PosItemVariant::class, 'pos_item_variant_id');
    }
}
