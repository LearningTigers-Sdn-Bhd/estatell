<?php

namespace App\Models;

use App\Enums\ChannelPropertyStatus;
use App\Enums\ChannelProvider;
use Carbon\CarbonImmutable;
use Database\Factories\ChannelPropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A property's copy at the channel manager: the provider's property id and the sync state. One row per property,
 * created on the first sync.
 *
 * @property int $id
 * @property int $property_id
 * @property ChannelProvider $provider
 * @property string $external_property_id
 * @property ChannelPropertyStatus $status
 * @property CarbonImmutable|null $paused_until
 * @property CarbonImmutable|null $structure_synced_at
 * @property CarbonImmutable|null $changes_sent_at
 * @property CarbonImmutable|null $full_synced_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'provider', 'external_property_id', 'status', 'paused_until', 'structure_synced_at', 'changes_sent_at', 'full_synced_at', 'last_error'])]
class ChannelProperty extends Model
{
    /** @use HasFactory<ChannelPropertyFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'provider' => ChannelProvider::class,
            'status' => ChannelPropertyStatus::class,
            'paused_until' => 'immutable_datetime',
            'structure_synced_at' => 'immutable_datetime',
            'changes_sent_at' => 'immutable_datetime',
            'full_synced_at' => 'immutable_datetime',
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
     * @return HasMany<ChannelAriChange, $this>
     */
    public function ariChanges(): HasMany
    {
        return $this->hasMany(ChannelAriChange::class);
    }

    /**
     * @return HasMany<ChannelBookingRevision, $this>
     */
    public function bookingRevisions(): HasMany
    {
        return $this->hasMany(ChannelBookingRevision::class);
    }

    /**
     * @return HasMany<ChannelAlert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(ChannelAlert::class);
    }

    /**
     * @return HasMany<ChannelRoomTypeMapping, $this>
     */
    public function roomTypeMappings(): HasMany
    {
        return $this->hasMany(ChannelRoomTypeMapping::class);
    }

    /**
     * @return HasMany<ChannelRatePlanMapping, $this>
     */
    public function ratePlanMappings(): HasMany
    {
        return $this->hasMany(ChannelRatePlanMapping::class);
    }
}
