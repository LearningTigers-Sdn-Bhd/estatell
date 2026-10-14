<?php

namespace App\Models;

use App\Enums\HousekeepingStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RoomStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The housekeeping state of one room. Every room has exactly one row, created with the room.
 *
 * @property int $id
 * @property int $property_id
 * @property int $room_id
 * @property HousekeepingStatus $status
 * @property bool $is_priority
 * @property string|null $priority_note
 * @property string|null $remark
 * @property bool $needs_maintenance
 * @property CarbonImmutable|null $do_not_disturb_on
 * @property int|null $assigned_to
 * @property int|null $last_changed_by
 * @property CarbonImmutable|null $last_changed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['status', 'is_priority', 'priority_note', 'remark', 'needs_maintenance', 'do_not_disturb_on', 'assigned_to', 'last_changed_by', 'last_changed_at'])]
class RoomStatus extends Model
{
    /** @use HasFactory<RoomStatusFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => HousekeepingStatus::class,
            'is_priority' => 'boolean',
            'needs_maintenance' => 'boolean',
            'do_not_disturb_on' => 'date',
            'last_changed_at' => 'datetime',
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
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lastChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_changed_by');
    }
}
