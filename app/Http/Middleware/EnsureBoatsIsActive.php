<?php

namespace App\Http\Middleware;

use App\Features\Boats;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to Boats routes while the property's Boats feature is off. Pennant's EnsureFeaturesAreActive is not
 * used here because its inactive handler is global and belongs to the Channels upsell.
 */
class EnsureBoatsIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        abort_unless($property instanceof Property && Feature::for($property)->active(Boats::class), 404);

        return $next($request);
    }
}
