<?php

namespace App\Models;

use Database\Factories\RateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The price and selling restrictions of one room type and rate plan for one date.
 *
 * @property int $id
 * @property int $room_type_id
 * @property int $rate_plan_id
 * @property Carbon $date
 * @property string|null $amount
 * @property int|null $min_stay
 * @property int|null $max_stay
 * @property bool $closed_to_arrival
 * @property bool $closed_to_departure
 * @property bool $stop_sell
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'room_type_id', 'rate_plan_id', 'date', 'amount', 'min_stay', 'max_stay',
    'closed_to_arrival', 'closed_to_departure', 'stop_sell',
])]
class Rate extends Model
{
    /** @use HasFactory<RateFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'min_stay' => 'integer',
            'max_stay' => 'integer',
            'closed_to_arrival' => 'boolean',
            'closed_to_departure' => 'boolean',
            'stop_sell' => 'boolean',
        ];
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
     * @return HasMany<RateOccupancyPrice, $this>
     */
    public function occupancyPrices(): HasMany
    {
        return $this->hasMany(RateOccupancyPrice::class);
    }

    /**
     * @return HasMany<RateHourPrice, $this>
     */
    public function hourPrices(): HasMany
    {
        return $this->hasMany(RateHourPrice::class);
    }

    /**
     * Rates that carry a price: a nightly amount, or hour prices on a Day use plan.
     *
     * @param  Builder<Rate>  $query
     */
    public function scopePriced(Builder $query): void
    {
        $query->where(fn (Builder $rates) => $rates
            ->whereNotNull('rates.amount')
            ->orWhereExists(fn ($hours) => $hours->selectRaw('1')->from('rate_hour_prices')->whereColumn('rate_hour_prices.rate_id', 'rates.id')));
    }
}
