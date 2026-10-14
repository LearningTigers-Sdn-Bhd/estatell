<?php

namespace App\Http\Middleware;

use App\Services\Platform\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a user who signed in with a temp password to the set-password page until they choose their own. Skipped
 * during a support session, because the support user must not choose the owner's password.
 */
class EnsurePasswordChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password
            && ! $request->routeIs('account.set-password.*', 'logout')
            && ! app(Impersonation::class)->isActive()) {
            return redirect()->route('account.set-password.edit');
        }

        return $next($request);
    }
}
