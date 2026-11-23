<?php

namespace App\Http\Middleware;

use App\Features\PosIntegration;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to POS routes (outlets, restaurant, POS postings) while the property's POS integration feature is off.
 */
class EnsurePosIntegrationIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        abort_unless($property instanceof Property && Feature::for($property)->active(PosIntegration::class), 404);

        return $next($request);
    }
}
