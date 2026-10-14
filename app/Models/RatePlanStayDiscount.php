<?php

namespace App\Models;

use App\Enums\StayDiscountType;
use Carbon\CarbonImmutable;
use Database\Factories\RatePlanStayDiscountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Stay at least N nights, get X off" on one rate plan. `from_night` 1 discounts every night, 2 keeps the first night at full price.
 *
 * @property int $id
 * @property int $rate_plan_id
 * @property int $min_nights
 * @property StayDiscountType $discount_type
 * @property string $value
 * @property int $from_night
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['min_nights', 'discount_type', 'value', 'from_night'])]
class RatePlanStayDiscount extends Model
{
    /** @use HasFactory<RatePlanStayDiscountFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'min_nights' => 'integer',
            'discount_type' => StayDiscountType::class,
            'value' => 'decimal:2',
            'from_night' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }
}
