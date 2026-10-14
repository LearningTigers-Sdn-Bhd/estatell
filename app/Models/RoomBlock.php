<?php

namespace App\Models;

use App\Enums\RoomBlockReason;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\RoomBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Takes a room, or one bed of it, out of sale from `starts_on` to `ends_on` (both nights included).
 * A block counts only while `completed_at` is null.
 *
 * @property int $id
 * @property int $property_id
 * @property int $room_id
 * @property int|null $bed_id
 * @property RoomBlockReason $reason
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string|null $notes
 * @property int $created_by
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['reason', 'starts_on', 'ends_on', 'notes'])]
class RoomBlock extends Model
{
    /** @use HasFactory<RoomBlockFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => RoomBlockReason::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<Bed, $this>
     */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Blocks that still count: not ended early and not cancelled.
     *
     * @param  Builder<RoomBlock>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('room_blocks.completed_at');
    }

    /**
     * Blocks that share at least one night with `$startsOn` to `$endsOn`.
     *
     * @param  Builder<RoomBlock>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface|string $startsOn, CarbonInterface|string $endsOn): void
    {
        $query->whereDate('room_blocks.starts_on', '<=', $endsOn)->whereDate('room_blocks.ends_on', '>=', $startsOn);
    }
}
