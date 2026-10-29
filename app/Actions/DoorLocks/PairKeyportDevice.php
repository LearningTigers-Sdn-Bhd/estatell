<?php

namespace App\Actions\DoorLocks;

use App\Data\DoorLocks\KeyportPairingData;
use App\Enums\KeyportApiError;
use App\Models\KeyportDevice;
use App\Models\KeyportPairingCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trade a pairing code for a device token. Returns an error, writing nothing, when the code is unknown, used or
 * expired.
 */
class PairKeyportDevice
{
    public function __invoke(string $code, string $deviceName, ?string $platform, ?string $agentVersion): KeyportPairingData|KeyportApiError
    {
        return DB::transaction(function () use ($code, $deviceName, $platform, $agentVersion): KeyportPairingData|KeyportApiError {
            $row = KeyportPairingCode::query()
                ->where('code_hash', hash('sha256', strtoupper(trim($code))))
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return KeyportApiError::InvalidPairingCode;
            }

            if ($row->used_at !== null) {
                return KeyportApiError::PairingCodeUsed;
            }

            if ($row->expires_at->isPast()) {
                return KeyportApiError::PairingCodeExpired;
            }

            $token = 'kp_live_'.Str::random(40);

            $device = new KeyportDevice(['name' => $deviceName, 'platform' => $platform, 'agent_version' => $agentVersion]);
            $device->forceFill([
                'public_id' => 'dev_'.Str::ulid(),
                'property_id' => $row->property_id,
                'token_hash' => hash('sha256', $token),
                'last_seen_at' => now(),
            ])->save();

            $row->forceFill(['used_at' => now(), 'device_id' => $device->id])->save();

            activity()
                ->performedOn($device)
                ->event('door-locks.keyport-paired')
                ->withProperties(['property_id' => $row->property_id])
                ->log('Keyport computer paired');

            return KeyportPairingData::fromDevice($device->load('property'), $token);
        });
    }
}
