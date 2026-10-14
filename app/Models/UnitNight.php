<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UnitNightFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One night of a unit: the quoted amounts at booking and the room or bed assigned for that night.
 * `room_id` is for Room room types and `bed_id` for Bed room types, never both. It has no timestamps.
 *
 * @property int $id
 * @property int $reservation_unit_id
 * @property CarbonImmutable $date
 * @property int $rate_plan_id
 * @property int|null $room_id
 * @property int|null $bed_id
 * @property string $base_amount
 * @property string $extra_amount
 * @property string $supplement_amount
 * @property string $discount_amount
 * @property string|null $override_amount The stay override of the unit spread over its nights. When set, it is `amount`.
 * @property string $amount
 * @property-read string $arrival_time `H:i:s`, only on rows selected with reservation_units.arrival_time.
 * @property-read string $departure_time `H:i:s`, only on rows selected with reservation_units.departure_time.
 */
#[Fillable([
    'date', 'rate_plan_id', 'room_id', 'bed_id', 'base_amount', 'extra_amount', 'supplement_amount',
    'discount_amount', 'override_amount', 'amount',
])]
class UnitNight extends Model
{
    /** @use HasFactory<UnitNightFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'base_amount' => 'decimal:2',
            'extra_amount' => 'decimal:2',
            'supplement_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'override_amount' => 'decimal:2',
            'amount' => 'decimal:2',
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
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
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
}
