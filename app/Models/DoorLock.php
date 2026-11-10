<?php

namespace App\Models;

use App\Enums\LockProvider;
use Carbon\CarbonImmutable;
use Database\Factories\DoorLockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A door lock of the property, linked to at most one room. A TTLock lock has `ttlock_lock_id`; an eLock lock has `lock_no` (for example 1.1.101) and opens only with a card Keyport writes. `has_gateway` and `battery_percent` come from the last sync.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $room_id
 * @property LockProvider $provider
 * @property int|null $ttlock_lock_id
 * @property string|null $lock_no
 * @property string $name
 * @property int|null $battery_percent
 * @property bool $has_gateway
 * @property string|null $gateway_name
 * @property bool|null $gateway_online
 * @property int|null $clock_drift_seconds Lock clock minus server clock, in seconds, from the last health check.
 * @property CarbonImmutable|null $health_checked_at
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['room_id', 'provider', 'ttlock_lock_id', 'lock_no', 'name', 'battery_percent', 'has_gateway', 'gateway_name', 'gateway_online', 'clock_drift_seconds', 'health_checked_at', 'synced_at'])]
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
            'provider' => LockProvider::class,
            'has_gateway' => 'boolean',
            'gateway_online' => 'boolean',
            'health_checked_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<DoorLock>  $query
     */
    #[Scope]
    protected function ofProvider(Builder $query, LockProvider $provider): void
    {
        $query->where('provider', $provider->value);
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
