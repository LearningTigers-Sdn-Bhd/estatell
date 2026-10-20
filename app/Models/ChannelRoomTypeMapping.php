<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ChannelRoomTypeMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a room type to its room type at the channel manager.
 *
 * @property int $id
 * @property int $channel_property_id
 * @property int $room_type_id
 * @property string $external_room_type_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['channel_property_id', 'room_type_id', 'external_room_type_id'])]
class ChannelRoomTypeMapping extends Model
{
    /** @use HasFactory<ChannelRoomTypeMappingFactory> */
    use HasFactory;

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
}
