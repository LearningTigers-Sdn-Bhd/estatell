<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ChannelRatePlanMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a rate plan and room type pair to its rate plan at the channel manager.
 *
 * @property int $id
 * @property int $channel_property_id
 * @property int $rate_plan_id
 * @property int $room_type_id
 * @property string $external_rate_plan_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['channel_property_id', 'rate_plan_id', 'room_type_id', 'external_rate_plan_id'])]
class ChannelRatePlanMapping extends Model
{
    /** @use HasFactory<ChannelRatePlanMappingFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ChannelProperty, $this>
     */
    public function channelProperty(): BelongsTo
    {
        return $this->belongsTo(ChannelProperty::class);
    }

    /**
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
