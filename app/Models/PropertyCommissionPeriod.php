<?php

namespace App\Models;

use App\Enums\CommissionService;
use Carbon\CarbonImmutable;
use Database\Factories\PropertyCommissionPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A period a property is charged commission for one service. An empty end date means it is still running.
 * See docs/30-commission-fees/02-commission-rules.md#charging-periods.
 *
 * @property int $id
 * @property int $property_id
 * @property CommissionService $service
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'service', 'starts_on', 'ends_on', 'created_by'])]
class PropertyCommissionPeriod extends Model
{
    /** @use HasFactory<PropertyCommissionPeriodFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service' => CommissionService::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
