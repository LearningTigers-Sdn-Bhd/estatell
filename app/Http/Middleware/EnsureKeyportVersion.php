<?php

namespace App\Http\Middleware;

use App\Enums\KeyportApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 426 to a Keyport agent that does not send a supported `X-Keyport-Version`, so it can tell staff to update.
 */
class EnsureKeyportVersion
{
    private const int OLDEST_SUPPORTED = 1;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ((int) $request->header('X-Keyport-Version') < self::OLDEST_SUPPORTED) {
            return KeyportApiError::UpgradeRequired->response();
        }

        return $next($request);
    }
}
