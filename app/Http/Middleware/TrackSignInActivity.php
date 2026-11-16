<?php

namespace App\Http\Middleware;

use App\Actions\Account\TouchSignInSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the sign-in log's last active time current for a signed-in browser. Inertia partial reloads are skipped,
 * because background polls (such as the business date) would make an unattended screen look active.
 */
class TrackSignInActivity
{
    public function __construct(private TouchSignInSession $touch) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && $request->hasSession() && ! $request->hasHeader('X-Inertia-Partial-Data')) {
            ($this->touch)($request->session());
        }

        return $next($request);
    }
}
