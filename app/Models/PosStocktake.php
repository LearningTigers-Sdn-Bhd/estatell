<?php

namespace App\Models;

use App\Enums\PosStocktakeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A closing count of an outlet's stock. Each line keeps the time it was counted.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property CarbonImmutable $business_date
 * @property PosStocktakeStatus $status
 * @property string|null $note
 * @property int $created_by_user_id
 * @property int|null $closed_by_user_id
 * @property CarbonImmutable|null $closed_at
 */
#[Fillable(['pos_outlet_id', 'business_date', 'status', 'note', 'created_by_user_id', 'closed_by_user_id', 'closed_at'])]
class PosStocktake extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'status' => PosStocktakeStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PosOutlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }

    /**
     * @return HasMany<PosStocktakeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PosStocktakeLine::class, 'pos_stocktake_id');
    }
}
