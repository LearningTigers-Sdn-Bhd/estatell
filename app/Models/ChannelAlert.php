<?php

namespace App\Models;

use App\Enums\ChannelAlertEvent;
use Carbon\CarbonImmutable;
use Database\Factories\ChannelAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A problem the channel manager reported by webhook. `payload` is the raw event. Alerts show on the admin Channels tab
 * only, until they are resolved.
 *
 * @property int $id
 * @property int $channel_property_id
 * @property ChannelAlertEvent $event
 * @property string|null $external_booking_id
 * @property string|null $revision_id
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['channel_property_id', 'event', 'external_booking_id', 'revision_id', 'payload', 'resolved_at'])]
class ChannelAlert extends Model
{
    /** @use HasFactory<ChannelAlertFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'event' => ChannelAlertEvent::class,
            'payload' => 'array',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<ChannelAlert>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /**
     * @return BelongsTo<ChannelProperty, $this>
     */
    public function channelProperty(): BelongsTo
    {
        return $this->belongsTo(ChannelProperty::class);
    }
}
