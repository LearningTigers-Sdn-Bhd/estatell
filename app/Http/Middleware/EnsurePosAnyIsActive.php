<?php

namespace App\Http\Middleware;

use App\Features\NativePos;
use App\Features\PosIntegration;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 unless the property has the external POS integration or Native POS on. Guards the pages both share
 * (outlets, restaurant meal check-in), so a Native POS only property can use them without turning on an integration.
 */
class EnsurePosAnyIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        abort_unless(
            $property instanceof Property && (Feature::for($property)->active(PosIntegration::class) || Feature::for($property)->active(NativePos::class)),
            404,
        );

        return $next($request);
    }
}
