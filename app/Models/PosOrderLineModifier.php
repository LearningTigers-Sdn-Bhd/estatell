<?php

namespace App\Models;

use Database\Factories\PosOrderLineModifierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chosen modifier option on an order line, with the price charged for it after free included choices.
 *
 * @property int $id
 * @property int $pos_order_line_id
 * @property int|null $pos_modifier_option_id
 * @property string $option_name
 * @property string|null $internal_name
 * @property string $price_delta
 * @property string $charged_price
 * @property int $quantity
 */
class PosOrderLineModifier extends Model
{
    /** @use HasFactory<PosOrderLineModifierFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'charged_price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PosOrderLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(PosOrderLine::class, 'pos_order_line_id');
    }
}
