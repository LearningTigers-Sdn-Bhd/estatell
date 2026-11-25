<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A variant a guest may pick for a combo slot, and what picking it adds to the combo price.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_combo_slot_id
 * @property int $pos_item_variant_id
 * @property string $upcharge
 * @property int $sort_order
 */
class PosComboSlotOption extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<PosItemVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(PosItemVariant::class, 'pos_item_variant_id');
    }
}
