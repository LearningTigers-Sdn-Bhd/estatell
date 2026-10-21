<?php

namespace App\Models;

use App\Enums\ChannelConnectionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ChannelConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One OTA connected to a property through the channel manager. `settings` is the adapter payload sent to Channex
 * (hotel ID and options). It never holds an OTA password.
 *
 * @property int $id
 * @property int $channel_property_id
 * @property int $ota_channel_id
 * @property string|null $external_channel_id
 * @property array<string, mixed> $settings
 * @property ChannelConnectionStatus $status
 * @property CarbonImmutable|null $activated_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['channel_property_id', 'ota_channel_id', 'external_channel_id', 'settings', 'status', 'activated_at', 'last_error'])]
class ChannelConnection extends Model
{
    /** @use HasFactory<ChannelConnectionFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'status' => ChannelConnectionStatus::class,
            'activated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ChannelProperty, $this>
     */
    public function channelProperty(): BelongsTo
    {
        return $this->belongsTo(ChannelProperty::class);
    }

    /**
     * @return HasMany<ChannelOtaMapping, $this>
     */
    public function otaMappings(): HasMany
    {
        return $this->hasMany(ChannelOtaMapping::class);
    }

    /**
     * @return BelongsTo<OtaChannel, $this>
     */
    public function otaChannel(): BelongsTo
    {
        return $this->belongsTo(OtaChannel::class);
    }
}
