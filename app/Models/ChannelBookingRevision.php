<?php

namespace App\Models;

use App\Enums\RevisionKind;
use Carbon\CarbonImmutable;
use Database\Factories\ChannelBookingRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One booking message from the channel manager, stored as it arrived. `payload` is the raw revision. `acknowledged_at`
 * is set only after the reservation is saved. A revision with `error` set was refused and waits for a retry.
 *
 * @property int $id
 * @property int $channel_property_id
 * @property int|null $channel_connection_id
 * @property string $external_booking_id
 * @property string $revision_id
 * @property RevisionKind $kind
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property int|null $reservation_id
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['channel_property_id', 'channel_connection_id', 'external_booking_id', 'revision_id', 'kind', 'payload', 'processed_at', 'acknowledged_at', 'reservation_id', 'error'])]
class ChannelBookingRevision extends Model
{
    /** @use HasFactory<ChannelBookingRevisionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => RevisionKind::class,
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
        ];
    }

    /**
     * Saved revisions wait for nothing. Everything else is still to do.
     *
     * @param  Builder<ChannelBookingRevision>  $query
     */
    public function scopeUnacknowledged(Builder $query): void
    {
        $query->whereNull('acknowledged_at');
    }

    /**
     * @param  Builder<ChannelBookingRevision>  $query
     */
    public function scopeFailed(Builder $query): void
    {
        $query->whereNull('acknowledged_at')->whereNotNull('error');
    }

    /**
     * @return BelongsTo<ChannelProperty, $this>
     */
    public function channelProperty(): BelongsTo
    {
        return $this->belongsTo(ChannelProperty::class);
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
