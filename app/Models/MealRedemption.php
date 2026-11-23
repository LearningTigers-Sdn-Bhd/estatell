<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MealRedemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One check-in event at the restaurant. A room with 4 guests entitled can have two events of 2. Rows are never deleted:
 * an undo sets `undone_at`.
 *
 * @property int $id
 * @property int $property_id
 * @property int $reservation_unit_id
 * @property int $meal_period_id
 * @property CarbonImmutable $service_date
 * @property int $redeemed_guests
 * @property int|null $redeemed_by_user_id
 * @property CarbonImmutable $redeemed_at
 * @property CarbonImmutable|null $undone_at
 * @property int|null $undone_by_user_id
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['reservation_unit_id', 'meal_period_id', 'service_date', 'redeemed_guests', 'redeemed_by_user_id', 'redeemed_at', 'undone_at', 'undone_by_user_id', 'notes'])]
class MealRedemption extends Model
{
    /** @use HasFactory<MealRedemptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_date' => 'immutable_date',
            'redeemed_at' => 'immutable_datetime',
            'undone_at' => 'immutable_datetime',
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
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }

    /**
     * @return BelongsTo<MealPeriod, $this>
     */
    public function mealPeriod(): BelongsTo
    {
        return $this->belongsTo(MealPeriod::class);
    }

    /**
     * @param  Builder<MealRedemption>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('undone_at');
    }
}
