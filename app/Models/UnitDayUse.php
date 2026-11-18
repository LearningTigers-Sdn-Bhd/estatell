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
 * so the exclusion constraint only covers live blocks. `vacated_at` (`H:i:s`) is set when the guest checked out before
 * `ends_at`: the room is free from then on, while `ends_at`, `hours` and `amount` keep what was sold.
 *
 * @property int $id
 * @property int $reservation_unit_id
 * @property CarbonImmutable $date
 * @property int|null $room_id
 * @property string $starts_at
 * @property string $ends_at
 * @property int $hours
 * @property string $amount
 * @property string|null $override_amount
 * @property CarbonImmutable|null $released_at
 * @property string|null $vacated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['date', 'room_id', 'starts_at', 'ends_at', 'hours', 'amount', 'override_amount', 'released_at', 'vacated_at'])]
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
            'override_amount' => 'decimal:2',
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
