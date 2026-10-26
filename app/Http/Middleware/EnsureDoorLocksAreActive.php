<?php

namespace App\Http\Middleware;

use App\Features\DoorLocks;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to door lock routes while the property's Door locks feature is off.
 */
class EnsureDoorLocksAreActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        abort_unless($property instanceof Property && Feature::for($property)->active(DoorLocks::class), 404);

        return $next($request);
    }
}
