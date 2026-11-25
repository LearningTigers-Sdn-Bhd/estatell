<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of an ingredient one item variant, or one modifier option, uses. A line deducts `quantity_in_base_unit` times its
 * quantity when it is fired.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $pos_item_variant_id
 * @property int|null $pos_modifier_option_id
 * @property int $pos_ingredient_id
 * @property string $quantity_in_base_unit
 */
#[Fillable(['property_id', 'pos_item_variant_id', 'pos_modifier_option_id', 'pos_ingredient_id', 'quantity_in_base_unit'])]
class PosRecipe extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_in_base_unit' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<PosIngredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(PosIngredient::class, 'pos_ingredient_id');
    }
}
