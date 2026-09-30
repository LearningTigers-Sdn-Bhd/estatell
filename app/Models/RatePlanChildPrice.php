<?php

namespace App\Models;

use App\Enums\AgeBandPricing;
use Database\Factories\RatePlanChildPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a per-guest rate plan charges for one age group. A percent is of the plan's 1-adult price for the night.
 *
 * @property int $id
 * @property int $rate_plan_id
 * @property int $property_age_group_id
 * @property AgeBandPricing $pricing
 * @property string|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['property_age_group_id', 'pricing', 'value'])]
class RatePlanChildPrice extends Model
{
    /** @use HasFactory<RatePlanChildPriceFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'pricing' => AgeBandPricing::class,
            'value' => 'decimal:2',
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
     * @return BelongsTo<PropertyAgeGroup, $this>
     */
    public function ageGroup(): BelongsTo
    {
        return $this->belongsTo(PropertyAgeGroup::class, 'property_age_group_id');
    }
}
