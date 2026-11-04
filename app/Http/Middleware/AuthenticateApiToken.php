<?php

namespace App\Http\Middleware;

use App\Actions\ApiAccess\RecordKioskUse;
use App\Http\Responses\ApiError;
use App\Models\Kiosk;
use Closure;
use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token check for the device and integration API (`api.token:{scope}`). Sanctum finds the token; this class
 * keeps the rest, so every refusal is our 401 JSON error. This app's exception handler maps an authentication exception
 * to `500 server_error`; that is not Sanctum's default response. The route names the ability it needs. The kiosk's
 * property is the only property the request can see: controllers read `$request->attributes->get('kiosk')->property`
 * and never take a property from the URL or body.
 */
class AuthenticateApiToken
{
    public function __construct(private RecordKioskUse $recordUse) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $kiosk = $this->usableKiosk($request);

        if ($kiosk === null || $kiosk->tokenCant($scope)) {
            return ApiError::response(
                str_starts_with($scope, 'kiosk:') ? 'kiosk_unauthorized' : 'unauthorized',
                __('This device is not signed in.'),
                401,
            );
        }

        $request->attributes->set('kiosk', $kiosk);
        ($this->recordUse)($kiosk);

        return $next($request);
    }

    /**
     * The kiosk behind the bearer token, or null when it is unknown, expired or revoked or its property is suspended.
     * The guard remembers its user for as long as the application lives, so it is cleared for each request.
     */
    private function usableKiosk(Request $request): ?Kiosk
    {
        /** @var RequestGuard $guard */
        $guard = Auth::guard('sanctum');
        /** @var mixed $kiosk Static analysis assumes the guard returns a User, but Sanctum returns the token owner. */
        $kiosk = $guard->setRequest($request)->forgetUser()->user();

        if (! $kiosk instanceof Kiosk || $kiosk->revoked_at !== null) {
            return null;
        }

        return $kiosk->loadMissing('property')->property->suspended_at === null ? $kiosk : null;
    }
}
