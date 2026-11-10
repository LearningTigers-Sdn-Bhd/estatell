<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RoomRevenuePolicyStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a paid cancellation policy: the charge when cancelled `days_before` days or more before arrival. The
 * charge is `percent` for a Percentage policy and `amount` for a Fixed amount policy. 0 is free.
 *
 * @property int $id
 * @property int $property_id
 * @property int $room_revenue_policy_id
 * @property int $days_before
 * @property string|null $amount
 * @property string|null $percent
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'room_revenue_policy_id', 'days_before', 'amount', 'percent'])]
class RoomRevenuePolicyStep extends Model
{
    /** @use HasFactory<RoomRevenuePolicyStepFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'days_before' => 'integer',
            'amount' => 'decimal:2',
            'percent' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<RoomRevenuePolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(RoomRevenuePolicy::class, 'room_revenue_policy_id');
    }
}
