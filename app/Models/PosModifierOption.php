<?php

namespace App\Models;

use App\Enums\PosAvailability;
use Carbon\CarbonImmutable;
use Database\Factories\PosModifierOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice in a modifier group, with a price change and an availability switch (the modifier-level 86).
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_modifier_group_id
 * @property string $name
 * @property string|null $internal_name
 * @property string $price_delta
 * @property PosAvailability $availability
 * @property int $sort_order
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'internal_name', 'price_delta', 'availability', 'sort_order', 'active'])]
class PosModifierOption extends Model
{
    /** @use HasFactory<PosModifierOptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'availability' => PosAvailability::class,
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
     * @return BelongsTo<PosModifierGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PosModifierGroup::class, 'pos_modifier_group_id');
    }
}
