<?php

namespace App\Http\Middleware;

use App\Services\Platform\Impersonation;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified as BaseEnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `verified` middleware, skipped during a support session so support can help an owner who has not verified
 * their email yet (see docs/01-platform/04-impersonation.md).
 */
class EnsureEmailIsVerified extends BaseEnsureEmailIsVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle($request, Closure $next, $redirectToRoute = null): mixed
    {
        if (app(Impersonation::class)->isActive()) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute);
    }
}
