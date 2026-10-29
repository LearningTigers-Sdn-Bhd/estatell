<?php

namespace App\Data\DoorLocks;

use App\Models\KeyportDevice;
use Spatie\LaravelData\Data;

/**
 * The answer to a successful pairing. The token appears here once and nowhere else. Field names follow the wire contract.
 */
class KeyportPairingData extends Data
{
    public function __construct(
        public string $device_id,
        public string $device_token,
        public string $property_code,
        public string $property_name,
        public int $poll_seconds,
    ) {}

    public static function fromDevice(KeyportDevice $device, string $token): self
    {
        return new self(
            device_id: $device->public_id,
            device_token: $token,
            property_code: $device->property->code,
            property_name: $device->property->name,
            poll_seconds: (int) config('keyport.poll_seconds'),
        );
    }
}
