<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Resources\Kiosk\V1\KioskConfigResource;
use App\Models\ApiToken;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    public function __invoke(Request $request): KioskConfigResource
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        return new KioskConfigResource($token->property->loadMissing('settings'));
    }
}
