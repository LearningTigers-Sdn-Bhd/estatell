<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks admin actions until the admin confirms two-factor, when `estatell.support_require_two_factor`
 * is on. Does nothing while the switch is off. See docs/01-platform/01-platform-admins.md#two-factor-for-admins.
 */
class RequireSupportTwoFactor
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::isMissingFor($request->user())) {
            return $next($request);
        }

        $message = __('Two-factor required for this action.');

        abort_if($request->expectsJson(), 403, $message);

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back(fallback: route('admin.dashboard'));
    }

    /**
     * Whether the user is an admin who still has to confirm two-factor before admin actions.
     */
    public static function isMissingFor(?User $user): bool
    {
        return $user !== null
            && $user->is_admin
            && config('estatell.support_require_two_factor')
            && $user->two_factor_confirmed_at === null;
    }

    /**
     * The shared `supportTwoFactor` prop: null unless an admin still has no confirmed two-factor.
     *
     * @return array{required: bool}|null
     */
    public static function sharedPropFor(?User $user): ?array
    {
        if ($user === null || ! $user->is_admin || $user->two_factor_confirmed_at !== null) {
            return null;
        }

        return ['required' => (bool) config('estatell.support_require_two_factor')];
    }
}
