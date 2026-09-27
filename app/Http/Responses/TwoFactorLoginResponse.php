<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

class TwoFactorLoginResponse extends LoginResponse implements TwoFactorLoginResponseContract
{
    protected function jsonResponse(): JsonResponse
    {
        return new JsonResponse('', 204);
    }
}
