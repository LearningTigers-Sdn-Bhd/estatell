<?php

namespace App\Models;

use App\Enums\AgeBandPricing;
use Carbon\CarbonImmutable;
use Database\Factories\ChargeItemChildPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a per-guest charge item charges for one age group. A percent is of the item's default amount.
 *
 * @property int $id
 * @property int $charge_item_id
 * @property int $property_age_group_id
 * @property AgeBandPricing $pricing
 * @property string|null $value
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_age_group_id', 'pricing', 'value'])]
class ChargeItemChildPrice extends Model
{
    /** @use HasFactory<ChargeItemChildPriceFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pricing' => AgeBandPricing::class,
            'value' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ChargeItem, $this> */
    public function chargeItem(): BelongsTo
    {
        return $this->belongsTo(ChargeItem::class);
    }

    /** @return BelongsTo<PropertyAgeGroup, $this> */
    public function ageGroup(): BelongsTo
    {
        return $this->belongsTo(PropertyAgeGroup::class, 'property_age_group_id');
    }
}
