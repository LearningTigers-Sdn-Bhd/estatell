<?php

namespace App\Http\Middleware;

use App\Enums\LockProvider;
use App\Features\DoorLocks;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to door lock routes while the property's Door locks feature is off. With a provider (`door-locks:ttlock` or
 * `door-locks:elock`) it also answers 404 when the property does not use that provider.
 */
class EnsureDoorLocksAreActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $provider = null): Response
    {
        $property = $request->route('property');

        abort_unless($property instanceof Property, 404);

        $allowed = $provider === null
            ? DoorLocks::providers($property) !== []
            : DoorLocks::allows($property, LockProvider::from($provider));

        abort_unless($allowed, 404);

        return $next($request);
    }
}
