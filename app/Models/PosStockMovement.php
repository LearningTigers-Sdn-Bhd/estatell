<?php

namespace App\Models;

use App\Enums\PosStockMovementKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the stock ledger. Rows are never changed: a correction is another row.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property int $pos_ingredient_id
 * @property PosStockMovementKind $kind
 * @property string $quantity_delta
 * @property string $unit_cost
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $reason
 * @property int|null $acted_by_user_id
 * @property CarbonImmutable $created_at
 */
#[Fillable(['property_id', 'pos_outlet_id', 'pos_ingredient_id', 'kind', 'quantity_delta', 'unit_cost', 'reference_type', 'reference_id', 'reason', 'acted_by_user_id', 'created_at'])]
class PosStockMovement extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PosStockMovementKind::class,
            'quantity_delta' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'created_at' => 'datetime',
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
