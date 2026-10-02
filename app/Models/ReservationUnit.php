<?php

namespace App\Models;

use App\Enums\UnitStatus;
use Database\Factories\ReservationUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One sellable room or bed in a reservation, with its own dates, plan, occupants and status.
 * `property_id` repeats the reservation's property so availability and audit queries need no join.
 *
 * @property int $id
 * @property int $reservation_id
 * @property int $property_id
 * @property int $room_type_id
 * @property int $rate_plan_id
 * @property Carbon $arrival_date
 * @property Carbon $departure_date
 * @property int $adults
 * @property int $children
 * @property UnitStatus $status
 * @property int|null $discount_id
 * @property bool $late_arrival_expected
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $checked_out_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'room_type_id', 'rate_plan_id', 'arrival_date', 'departure_date', 'adults', 'children', 'discount_id',
    'late_arrival_expected',
])]
class ReservationUnit extends Model
{
    /** @use HasFactory<ReservationUnitFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arrival_date' => 'date',
            'departure_date' => 'date',
            'status' => UnitStatus::class,
            'late_arrival_expected' => 'boolean',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /**
     * @return BelongsTo<Discount, $this>
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /**
     * @return HasMany<UnitNight, $this>
     */
    public function nights(): HasMany
    {
        return $this->hasMany(UnitNight::class);
    }

    /**
     * @return HasMany<UnitGuest, $this>
     */
    public function guests(): HasMany
    {
        return $this->hasMany(UnitGuest::class);
    }

    /**
     * @return HasMany<ReservationUnitChild, $this>
     */
    public function childAges(): HasMany
    {
        return $this->hasMany(ReservationUnitChild::class);
    }

    /**
     * @return HasMany<UnitAddOn, $this>
     */
    public function addOns(): HasMany
    {
        return $this->hasMany(UnitAddOn::class);
    }
}
