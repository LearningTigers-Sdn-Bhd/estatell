<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UnitDayUseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The room, the hours and the price of a day use unit. It is the day use counterpart of `unit_nights`.
 * `starts_at` and `ends_at` are `H:i:s` times on `date`. `released_at` is set while the unit is Cancelled or NoShow,
 * so the exclusion constraint only covers live blocks.
 *
 * @property int $id
 * @property int $reservation_unit_id
 * @property CarbonImmutable $date
 * @property int|null $room_id
 * @property string $starts_at
 * @property string $ends_at
 * @property int $hours
 * @property string $amount
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['date', 'room_id', 'starts_at', 'ends_at', 'hours', 'amount', 'released_at'])]
class UnitDayUse extends Model
{
    /** @use HasFactory<UnitDayUseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'hours' => 'integer',
            'amount' => 'decimal:2',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
