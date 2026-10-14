<?php

namespace App\Http\Middleware;

use App\Models\Property;
use App\Services\Platform\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Denies the routes a support user must not reach while viewing a property as its staff (see
 * {@see Impersonation::isBlockedRoute()}). It sits in the web group, so Fortify's vendor routes are covered too.
 * Inertia visits and page requests get a toast and a redirect back; JSON requests get a 403.
 */
class BlockDuringImpersonation
{
    public function __construct(private Impersonation $impersonation) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if ($routeName === null || ! $this->impersonation->isActive() || ! $this->impersonation->isBlockedRoute($routeName, $request->method())) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json(['message' => __('Not available during support access')], 403);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => __('Not available during support access')]);

        $property = $request->route('property');
        $fallback = $property instanceof Property ? route('dashboard', ['property' => $property->code], absolute: false) : '/';

        return redirect()->back(fallback: $fallback);
    }
}
