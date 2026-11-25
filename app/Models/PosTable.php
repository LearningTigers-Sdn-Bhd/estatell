<?php

namespace App\Models;

use App\Enums\PosTableShape;
use Carbon\CarbonImmutable;
use Database\Factories\PosTableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A table on a floor plan, placed by its x/y position and size.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_floor_id
 * @property string $code
 * @property string|null $label
 * @property int $seats
 * @property PosTableShape $shape
 * @property int $pos_x
 * @property int $pos_y
 * @property int $width
 * @property int $height
 * @property string|null $static_qr_token
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'label', 'seats', 'shape', 'pos_x', 'pos_y', 'width', 'height', 'active'])]
class PosTable extends Model
{
    /** @use HasFactory<PosTableFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'shape' => PosTableShape::class,
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PosFloor, $this>
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(PosFloor::class, 'pos_floor_id');
    }

    /**
     * @return HasMany<PosOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(PosOrder::class, 'pos_table_id');
    }
}
