<?php

namespace App\Data\DoorLocks;

use App\Models\DoorLock;
use Spatie\LaravelData\Data;

class DoorLockData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $batteryPercent,
        public bool $hasGateway,
        public ?int $roomId,
        public ?string $roomNumber,
        public ?string $syncedAt,
        public ?string $gatewayName,
        public ?bool $gatewayOnline,
        public ?int $clockDriftSeconds,
        public ?string $healthCheckedAt,
    ) {}

    /**
     * Needs the `room` relation loaded.
     */
    public static function fromModel(DoorLock $lock): self
    {
        return new self(
            id: $lock->id,
            name: $lock->name,
            batteryPercent: $lock->battery_percent,
            hasGateway: $lock->has_gateway,
            roomId: $lock->room_id,
            roomNumber: $lock->room?->number,
            syncedAt: $lock->synced_at?->toIso8601String(),
            gatewayName: $lock->gateway_name,
            gatewayOnline: $lock->gateway_online,
            clockDriftSeconds: $lock->clock_drift_seconds,
            healthCheckedAt: $lock->health_checked_at?->toIso8601String(),
        );
    }
}
