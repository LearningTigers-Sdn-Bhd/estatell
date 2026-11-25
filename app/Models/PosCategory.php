<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A menu category. It gives its items a default transaction code, which owns the GL account and taxes.
 *
 * @property int $id
 * @property int $property_id
 * @property int $transaction_code_id
 * @property int|null $kitchen_station_id
 * @property string $name
 * @property string|null $internal_name
 * @property string $color_token
 * @property int $sort_order
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['transaction_code_id', 'name', 'internal_name', 'color_token', 'sort_order', 'active'])]
class PosCategory extends Model
{
    /** @use HasFactory<PosCategoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return BelongsTo<TransactionCode, $this>
     */
    public function transactionCode(): BelongsTo
    {
        return $this->belongsTo(TransactionCode::class, 'transaction_code_id');
    }

    /**
     * @return HasMany<PosItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PosItem::class, 'pos_category_id');
    }
}
