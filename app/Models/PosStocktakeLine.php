<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ingredient counted on a stocktake, with the expected quantity at the moment of the count once the stocktake is closed.
 *
 * @property int $id
 * @property int $pos_stocktake_id
 * @property int $pos_ingredient_id
 * @property CarbonImmutable $counted_at
 * @property string $counted_quantity
 * @property string|null $expected_quantity
 * @property string|null $variance_quantity
 * @property string|null $variance_cost
 */
#[Fillable(['pos_stocktake_id', 'pos_ingredient_id', 'counted_at', 'counted_quantity', 'expected_quantity', 'variance_quantity', 'variance_cost'])]
class PosStocktakeLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'counted_at' => 'datetime',
            'counted_quantity' => 'decimal:4',
            'expected_quantity' => 'decimal:4',
            'variance_quantity' => 'decimal:4',
            'variance_cost' => 'decimal:2',
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
