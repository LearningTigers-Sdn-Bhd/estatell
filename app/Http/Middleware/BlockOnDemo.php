<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the demo environment (`APP_ENV=demo`), denies signup and the account security writes, so nobody creates an
 * account there and no visitor locks the others out of the shared logins. It sits in the web group, so Fortify's
 * vendor routes are covered too. Inertia visits and page requests get a toast and a redirect; JSON requests get a 403.
 * See docs/01-platform/06-demo-properties.md#guards.
 */
class BlockOnDemo
{
    /**
     * Blocked for every method.
     *
     * @var list<string>
     */
    private const array BLOCKED_ROUTES = [
        'register',
        'register.store',
    ];

    /**
     * Blocked for every method but GET and HEAD.
     *
     * @var list<string>
     */
    private const array BLOCKED_NON_GET_ROUTES = [
        'account.profile.*',
        'account.set-password.*',
        'account.password.*',
        'account.sessions.*',
        'two-factor.enable',
        'two-factor.disable',
        'two-factor.confirm',
        'two-factor.regenerate-recovery-codes',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if ($routeName === null || ! app()->environment('demo') || ! $this->isBlocked($routeName, $request->method())) {
            return $next($request);
        }

        $message = __('Not available in the demo');

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return in_array($routeName, self::BLOCKED_ROUTES, true)
            ? redirect()->route('login')
            : redirect()->back(fallback: '/');
    }

    private function isBlocked(string $routeName, string $method): bool
    {
        if (in_array($routeName, self::BLOCKED_ROUTES, true)) {
            return true;
        }

        return ! in_array(Str::upper($method), ['GET', 'HEAD'], true) && Str::is(self::BLOCKED_NON_GET_ROUTES, $routeName);
    }
}
