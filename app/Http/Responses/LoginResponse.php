<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    /**
     * Send admins to /admin and staff to the Fortify home, unless an intended URL was stored.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return $this->jsonResponse();
        }

        $home = $request->user()?->is_admin ? route('admin.dashboard') : Fortify::redirects('login');

        return redirect()->intended($home);
    }

    protected function jsonResponse(): JsonResponse
    {
        return response()->json(['two_factor' => false]);
    }
}
