<?php

namespace App\Models;

use App\Enums\AriChangeKind;
use Carbon\CarbonImmutable;
use Database\Factories\ChannelAriChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the ARI outbox: a date range of a room type (and rate plan) that must be sent to the channel manager
 * again. The values are not stored; the flush calculates them when it sends. `sent_at` null means waiting.
 *
 * @property int $id
 * @property int $channel_property_id
 * @property int $room_type_id
 * @property int|null $rate_plan_id
 * @property AriChangeKind $kind
 * @property CarbonImmutable $date_from
 * @property CarbonImmutable $date_to
 * @property CarbonImmutable|null $sent_at
 * @property string|null $task_id
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['channel_property_id', 'room_type_id', 'rate_plan_id', 'kind', 'date_from', 'date_to', 'sent_at', 'task_id', 'attempts', 'last_error'])]
class ChannelAriChange extends Model
{
    /** @use HasFactory<ChannelAriChangeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => AriChangeKind::class,
            'date_from' => 'immutable_date',
            'date_to' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @param  Builder<ChannelAriChange>  $query
     */
    public function scopeWaiting(Builder $query): void
    {
        $query->whereNull('sent_at');
    }

    /**
     * @return BelongsTo<ChannelProperty, $this>
     */
    public function channelProperty(): BelongsTo
    {
        return $this->belongsTo(ChannelProperty::class);
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
}
