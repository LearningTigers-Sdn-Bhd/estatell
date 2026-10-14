<?php

namespace App\Http\Middleware;

use App\Actions\Platform\EndExpiredImpersonation;
use App\Services\Platform\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a support session that has run past its 30-minute limit on the next web request, whatever the page, and
 * sends the support user back to where the session started.
 */
class EndExpiredImpersonationSession
{
    public function __construct(private Impersonation $impersonation, private EndExpiredImpersonation $endExpired) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->impersonation->hasExpired()) {
            return $next($request);
        }

        $minutes = $this->impersonation->elapsedMinutes();
        $returnUrl = ($this->endExpired)();

        if ($returnUrl === null) {
            return $next($request);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Support session ended (:minutes min).', ['minutes' => $minutes])]);

        return redirect($returnUrl);
    }
}
