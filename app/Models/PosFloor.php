<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosFloorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dining area of an outlet (Indoor, Terrace) that holds its tables.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property string $name
 * @property int $sort_order
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'sort_order', 'active'])]
class PosFloor extends Model
{
    /** @use HasFactory<PosFloorFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
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
     * @return HasMany<PosTable, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(PosTable::class, 'pos_floor_id');
    }
}
