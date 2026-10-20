<?php

namespace App\Models;

use App\Enums\ChannelSellMode;
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
 * @property ChannelSellMode $sell_mode How the plan was sent to Channex. The ARI values follow it, so a plan changed in estatell keeps its old shape until the structure is synced again.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['channel_property_id', 'rate_plan_id', 'room_type_id', 'external_rate_plan_id', 'sell_mode'])]
class ChannelRatePlanMapping extends Model
{
    /** @use HasFactory<ChannelRatePlanMappingFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['sell_mode' => ChannelSellMode::class];
    }

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
