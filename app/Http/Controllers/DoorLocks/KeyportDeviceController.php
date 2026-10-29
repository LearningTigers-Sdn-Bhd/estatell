<?php

namespace App\Http\Controllers\DoorLocks;

use App\Actions\DoorLocks\PairKeyportDevice;
use App\Actions\DoorLocks\RevokeKeyportDevice;
use App\Enums\KeyportApiError;
use App\Http\Controllers\Controller;
use App\Http\Requests\DoorLocks\PairKeyportDeviceRequest;
use App\Models\KeyportDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Keyport PC's own calls about itself: pair with a code, unpair.
 */
class KeyportDeviceController extends Controller
{
    public function pair(PairKeyportDeviceRequest $request, PairKeyportDevice $pair): JsonResponse
    {
        $result = $pair(
            $request->string('pairing_code')->toString(),
            $request->string('device_name')->toString(),
            $request->input('platform'),
            $request->input('agent_version'),
        );

        return $result instanceof KeyportApiError ? $result->response() : response()->json($result->toArray());
    }

    public function unpair(Request $request, RevokeKeyportDevice $revoke): Response
    {
        $device = $request->attributes->get('keyport_device');
        assert($device instanceof KeyportDevice);

        $revoke(null, $device);

        return response()->noContent();
    }
}
