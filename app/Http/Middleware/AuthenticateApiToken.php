<?php

namespace App\Http\Middleware;

use App\Actions\ApiAccess\RecordApiTokenUse;
use App\Http\Responses\ApiError;
use App\Services\ApiAccess\ApiTokenGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token guard for the device and integration API (`api.token:{scope}`). The route names the scope it needs; a
 * token without it is refused like an unknown token. The token's property is the only property the request can see:
 * controllers read `$request->attributes->get('api_token')->property` and never take a property from the URL or body.
 */
class AuthenticateApiToken
{
    public function __construct(private ApiTokenGate $gate, private RecordApiTokenUse $recordUse) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = $this->gate->find($request->bearerToken());

        if ($token === null || ! $this->gate->allows($token, $scope)) {
            $key = 'api-token-rejections:'.(string) $request->ip();

            if (RateLimiter::hit($key, 60) > 120) {
                return ApiError::response('rate_limited', __('Too many requests. Wait a moment and try again.'), 429,
                    ['Retry-After' => (string) RateLimiter::availableIn($key)]);
            }

            return ApiError::response(
                str_starts_with($scope, 'kiosk:') ? 'kiosk_unauthorized' : 'unauthorized',
                __('This device is not signed in.'),
                401,
            );
        }

        $request->attributes->set('api_token', $token);
        ($this->recordUse)($token);

        return $next($request);
    }
}
