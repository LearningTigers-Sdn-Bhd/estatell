<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DoorLockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A TTLock lock of the property, linked to at most one room. `has_gateway` and `battery_percent` come from the last sync.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $room_id
 * @property int $ttlock_lock_id
 * @property string $name
 * @property int|null $battery_percent
 * @property bool $has_gateway
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['room_id', 'ttlock_lock_id', 'name', 'battery_percent', 'has_gateway', 'synced_at'])]
class DoorLock extends Model
{
    /** @use HasFactory<DoorLockFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'has_gateway' => 'boolean',
            'synced_at' => 'immutable_datetime',
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
     * @return HasMany<DoorPasscode, $this>
     */
    public function passcodes(): HasMany
    {
        return $this->hasMany(DoorPasscode::class);
    }
}
