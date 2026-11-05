<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Actions\ApiAccess\PairKiosk;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApiAccess\PairKioskRequest;
use App\Http\Resources\ApiAccess\PairedKioskResource;
use App\Http\Responses\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PairController extends Controller
{
    public function __invoke(PairKioskRequest $request, PairKiosk $pairKiosk): PairedKioskResource|JsonResponse
    {
        $deviceName = Str::limit(trim((string) $request->input('device_name')), 100, '');

        $paired = $pairKiosk((string) $request->input('pairing_code'), $deviceName === '' ? null : $deviceName);

        return $paired === null
            ? ApiError::response('kiosk_unauthorized', __('That pairing code is not valid.'), 401)
            : new PairedKioskResource($paired);
    }
}
