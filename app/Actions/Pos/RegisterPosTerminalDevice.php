<?php

namespace App\Actions\Pos;

use App\Models\PosOutlet;
use App\Models\PosTerminalDevice;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Register the browser in hand as a shared POS till for one outlet. Returns the device and its plain token, which the
 * caller puts in the signed HttpOnly cookie; only the SHA-256 hash is stored. Returns null when the outlet is not the
 * property's.
 */
class RegisterPosTerminalDevice
{
    /**
     * @return array{device: PosTerminalDevice, token: string}|null
     */
    public function __invoke(User $actor, Property $property, PosOutlet $outlet, string $name): ?array
    {
        if ($outlet->property_id !== $property->id) {
            return null;
        }

        return DB::transaction(function () use ($actor, $property, $outlet, $name): array {
            $token = Str::random(64);

            $device = new PosTerminalDevice;
            $device->fill(['name' => trim($name)]);
            $device->property_id = $property->id;
            $device->pos_outlet_id = $outlet->id;
            $device->device_token_hash = hash('sha256', $token);
            $device->save();

            activity()->performedOn($device)->causedBy($actor)->event('pos.device_registered')->log('POS terminal registered');

            return ['device' => $device, 'token' => $token];
        });
    }
}
