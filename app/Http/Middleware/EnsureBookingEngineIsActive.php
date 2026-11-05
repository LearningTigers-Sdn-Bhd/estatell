<?php

namespace App\Http\Middleware;

use App\Enums\BookingEngineMode;
use App\Features\BookingEngine;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to booking website routes while the property's Booking engine feature is off. Shares `bookingMode`, so
 * the tab links show only the tabs of the property's mode. A site that was never opened is in Microsite mode.
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

        Inertia::share('bookingMode', fn (): string => ($property->bookingSite()->first()?->mode ?? BookingEngineMode::Microsite)->value);

        return $next($request);
    }
}
