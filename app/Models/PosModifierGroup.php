<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosModifierGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A set of choices an item offers (milk, size, extras) with how many a guest must or may pick.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property int $min_select
 * @property int|null $max_select
 * @property int $max_per_option
 * @property int $free_included_count
 * @property int $sort_order
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'min_select', 'max_select', 'max_per_option', 'free_included_count', 'sort_order', 'active'])]
class PosModifierGroup extends Model
{
    /** @use HasFactory<PosModifierGroupFactory> */
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
     * @return HasMany<PosModifierOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(PosModifierOption::class, 'pos_modifier_group_id');
    }

    /**
     * @return BelongsToMany<PosItem, $this>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(PosItem::class, 'pos_item_modifier_group', 'pos_modifier_group_id', 'pos_item_id')->withPivot('sort_order');
    }
}
