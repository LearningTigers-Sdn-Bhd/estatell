<?php

namespace App\Http\Middleware;

use App\Enums\PartnerStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user who was deactivated, or of a partner user whose partner was suspended, while signed
 * in, on any web request.
 */
class LogoutDeactivatedUsers
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $status = match (true) {
            $user->deactivated_at !== null => __('Your account is deactivated. Ask the property owner to reactivate it.'),
            $user->partner_id !== null && $user->loadMissing('partner')->partner?->status === PartnerStatus::Suspended => __('Your partner account is suspended. Contact estatell support.'),
            default => null,
        };

        if ($status !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', $status);
        }

        return $next($request);
    }
}
