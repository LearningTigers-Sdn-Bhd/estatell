<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiAccess\KioskMeResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): KioskMeResource
    {
        return new KioskMeResource($request->attributes->get('api_token'));
    }
}
