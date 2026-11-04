<?php

namespace App\Http\Middleware;

use App\Actions\ApiAccess\RecordBookingClientUse;
use App\Actions\ApiAccess\RecordKioskUse;
use App\Features\Kiosks;
use App\Http\Responses\ApiError;
use App\Models\BookingClient;
use App\Models\Kiosk;
use Closure;
use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token check for the device and integration API (`api.token:{scope}`). Sanctum finds the token; this class
 * keeps the rest, so every refusal is our 401 JSON error. This app's exception handler maps an authentication exception
 * to `500 server_error`; that is not Sanctum's default response. The route names the ability it needs.
 *
 * A kiosk's property is the only property the request can see: controllers read
 * `$request->attributes->get('kiosk')->property` and never take a property from the URL or body. A booking client is
 * set as the `booking_client` attribute, and only ever passes a `booking:` route; `EnsureBookingClientReach` then checks
 * the property in the path.
 *
 * `AuthenticatesRequests` puts this class before `throttle` in Laravel's middleware order, because the `api-token` limiter
 * reads the attribute this class sets. Without it, the limiter counts per IP address.
 */
class AuthenticateApiToken implements AuthenticatesRequests
{
    public function __construct(private RecordKioskUse $recordKioskUse, private RecordBookingClientUse $recordClientUse) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $owner = $this->usableOwner($request);

        if ($owner === null || $owner->tokenCant($scope) || ($owner instanceof BookingClient) !== str_starts_with($scope, 'booking:')) {
            $key = 'api-token-rejections:'.(string) $request->ip();

            if (RateLimiter::hit($key, 60) > 120) {
                return ApiError::response('rate_limited', __('Too many requests. Wait a moment and try again.'), 429,
                    ['Retry-After' => (string) RateLimiter::availableIn($key)]);
            }

            return str_starts_with($scope, 'kiosk:')
                ? ApiError::response('kiosk_unauthorized', __('This device is not signed in.'), 401)
                : ApiError::response('unauthorized', __('This API token is not valid.'), 401);
        }

        if ($owner instanceof Kiosk) {
            $request->attributes->set('kiosk', $owner);
            ($this->recordKioskUse)($owner);
        } else {
            $request->attributes->set('booking_client', $owner);
            ($this->recordClientUse)($owner);
        }

        return $next($request);
    }

    /**
     * The owner of the bearer token, or null when the token is unknown or expired, the owner is revoked, or a kiosk's
     * property is suspended or has the Kiosks feature off. The guard remembers its user for as long as the application
     * lives, so it is cleared for each request.
     */
    private function usableOwner(Request $request): Kiosk|BookingClient|null
    {
        /** @var RequestGuard $guard */
        $guard = Auth::guard('sanctum');
        /** @var mixed $owner Static analysis assumes the guard returns a User, but Sanctum returns the token owner. */
        $owner = $guard->setRequest($request)->forgetUser()->user();

        if ($owner instanceof BookingClient) {
            return $owner->revoked_at === null ? $owner : null;
        }

        if (! $owner instanceof Kiosk || $owner->revoked_at !== null) {
            return null;
        }

        $property = $owner->loadMissing('property')->property;

        return $property->suspended_at === null && Feature::for($property)->active(Kiosks::class) ? $owner : null;
    }
}
