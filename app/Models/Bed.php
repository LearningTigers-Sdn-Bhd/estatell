<?php

namespace App\Models;

use Database\Factories\BedFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A sellable bed in a room whose room type sells beds. It has no `property_id`: the room carries it.
 *
 * @property int $id
 * @property int $room_id
 * @property string $label
 * @property int $position
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['label', 'position', 'archived_at'])]
class Bed extends Model
{
    /** @use HasFactory<BedFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<RoomBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(RoomBlock::class);
    }

    /**
     * @param  Builder<Bed>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<Bed>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('beds.position')->orderBy('beds.id');
    }
}
