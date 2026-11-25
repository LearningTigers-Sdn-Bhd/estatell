<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One choice a combo asks for, such as "Choose Main", with how many the guest picks.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_item_id
 * @property string $name
 * @property int $min_select
 * @property int $max_select
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PosComboSlot extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<PosItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PosItem::class, 'pos_item_id');
    }

    /**
     * @return HasMany<PosComboSlotOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(PosComboSlotOption::class, 'pos_combo_slot_id')->orderBy('sort_order')->orderBy('id');
    }
}
