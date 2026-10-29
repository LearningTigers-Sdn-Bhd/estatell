<?php

namespace App\Http\Middleware;

use App\Enums\KeyportApiError;
use App\Features\DoorLocks;
use App\Models\KeyportDevice;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the paired PC from its bearer token (by hash) and refuses revoked devices and properties without Door locks.
 * The property always comes from the device, never from the request. The device is left in `keyport_device`.
 */
class AuthenticateKeyportDevice
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $device = $token === null ? null : KeyportDevice::query()
            ->with('property.settings')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();

        if ($device === null) {
            return KeyportApiError::Unauthorized->response();
        }

        if (! Feature::for($device->property)->active(DoorLocks::class)) {
            return KeyportApiError::DoorLocksDisabled->response();
        }

        $request->attributes->set('keyport_device', $device);

        return $next($request);
    }
}
