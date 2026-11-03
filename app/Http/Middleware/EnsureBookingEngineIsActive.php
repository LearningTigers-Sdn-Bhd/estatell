<?php

namespace App\Http\Middleware;

use App\Features\BookingEngine;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to booking website routes while the property's Booking engine feature is off.
 */
class EnsureBookingEngineIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        abort_unless($property instanceof Property && Feature::for($property)->active(BookingEngine::class), 404);

        return $next($request);
    }
}
