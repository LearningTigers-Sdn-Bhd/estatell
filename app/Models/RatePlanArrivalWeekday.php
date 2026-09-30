<?php

namespace App\Models;

use App\Enums\Weekday;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A weekday a package can start on. A package with no rows can start on any day.
 *
 * @property int $id
 * @property int $rate_plan_id
 * @property Weekday $weekday
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['weekday'])]
class RatePlanArrivalWeekday extends Model
{
    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'weekday' => Weekday::class,
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
