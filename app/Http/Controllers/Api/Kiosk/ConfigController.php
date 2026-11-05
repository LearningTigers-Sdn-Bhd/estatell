<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Resources\Kiosk\V1\KioskConfigResource;
use App\Models\Kiosk;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    public function __invoke(Request $request): KioskConfigResource
    {
        /** @var Kiosk $kiosk */
        $kiosk = $request->attributes->get('kiosk');

        return new KioskConfigResource($kiosk->property->loadMissing('settings'));
    }
}
