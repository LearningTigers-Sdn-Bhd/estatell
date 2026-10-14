<?php

namespace App\Models;

use App\Enums\ChargingUnit;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use Carbon\CarbonImmutable;
use Database\Factories\RatePlanInclusionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a rate plan includes in its price: a meal, transfer or activity. `allocated_amount` is the revenue
 * moved from the room to the charge item for each charging unit, so a line is amount x quantity x units.
 *
 * @property int $id
 * @property int $rate_plan_id
 * @property int $charge_item_id
 * @property int|null $meal_period_id
 * @property ChargingUnit $charging_unit
 * @property int $quantity
 * @property string $allocated_amount
 * @property InclusionStart $starts_on
 * @property InclusionEnd $ends_on
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'charge_item_id', 'meal_period_id', 'charging_unit', 'quantity', 'allocated_amount',
    'starts_on', 'ends_on', 'position',
])]
class RatePlanInclusion extends Model
{
    /** @use HasFactory<RatePlanInclusionFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'charging_unit' => ChargingUnit::class,
            'quantity' => 'integer',
            'allocated_amount' => 'decimal:2',
            'starts_on' => InclusionStart::class,
            'ends_on' => InclusionEnd::class,
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /**
     * @return BelongsTo<ChargeItem, $this>
     */
    public function chargeItem(): BelongsTo
    {
        return $this->belongsTo(ChargeItem::class);
    }

    /**
     * @return BelongsTo<MealPeriod, $this>
     */
    public function mealPeriod(): BelongsTo
    {
        return $this->belongsTo(MealPeriod::class);
    }
}
