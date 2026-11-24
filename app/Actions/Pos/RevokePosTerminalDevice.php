<?php

namespace App\Actions\Pos;

use App\Models\PosTerminalDevice;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoke a registered POS till. Its cookie stops working at once. Returns false when it is not the property's or already revoked.
 */
class RevokePosTerminalDevice
{
    public function __invoke(User $actor, Property $property, PosTerminalDevice $device): bool
    {
        return DB::transaction(function () use ($actor, $property, $device): bool {
            $device = PosTerminalDevice::query()->where('property_id', $property->id)->whereNull('revoked_at')->lockForUpdate()->find($device->id);

            if ($device === null) {
                return false;
            }

            $device->forceFill(['revoked_at' => now()])->save();

            activity()->performedOn($device)->causedBy($actor)->event('pos.device_revoked')->log('POS terminal revoked');

            return true;
        });
    }
}
