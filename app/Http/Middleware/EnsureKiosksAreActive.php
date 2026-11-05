<?php

namespace App\Http\Middleware;

use App\Features\Kiosks;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to the kiosk settings routes while the property's Kiosks feature is off.
 */
class EnsureKiosksAreActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        abort_unless($property instanceof Property && Feature::for($property)->active(Kiosks::class), 404);

        return $next($request);
    }
}
