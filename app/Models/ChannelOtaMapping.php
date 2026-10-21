<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ChannelOtaMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps one occupancy of a Channex rate plan to one room and rate of an OTA. `is_primary` and `rate_type` follow the
 * adapter's `rate_params`: Booking.com, Agoda and Expedia use `is_primary`, Hostelworld uses `rate_type`.
 *
 * @property int $id
 * @property int $channel_connection_id
 * @property int $channel_rate_plan_mapping_id
 * @property int $occupancy
 * @property string $ota_room_code
 * @property string $ota_rate_code
 * @property bool|null $is_primary
 * @property string|null $rate_type
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['channel_connection_id', 'channel_rate_plan_mapping_id', 'occupancy', 'ota_room_code', 'ota_rate_code', 'is_primary', 'rate_type'])]
class ChannelOtaMapping extends Model
{
    /** @use HasFactory<ChannelOtaMappingFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['occupancy' => 'integer', 'is_primary' => 'boolean'];
    }

    /**
     * @return BelongsTo<ChannelConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    /**
     * @return BelongsTo<ChannelRatePlanMapping, $this>
     */
    public function ratePlanMapping(): BelongsTo
    {
        return $this->belongsTo(ChannelRatePlanMapping::class, 'channel_rate_plan_mapping_id');
    }
}
