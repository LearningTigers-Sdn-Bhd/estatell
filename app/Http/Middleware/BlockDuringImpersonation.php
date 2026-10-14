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

        if ($routeName === null || ! $this->impersonation->isActive()) {
            return $next($request);
        }

        $message = match (true) {
            $this->impersonation->isBlockedRoute($routeName, $request->method()) => __('Not available during support access'),
            $this->impersonation->isMoneyCorrectionRoute($routeName) && ! $this->impersonation->canCorrectMoney() => __('The owner has not allowed refunds and voids during support access'),
            default => null,
        };

        if ($message === null) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        $property = $request->route('property');
        $fallback = $property instanceof Property ? route('dashboard', ['property' => $property->code], absolute: false) : '/';

        return redirect()->back(fallback: $fallback);
    }
}
