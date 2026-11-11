<?php

namespace App\Http\Middleware;

use App\Features\EInvoicing;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to the e-invoice routes while the property's EInvoicing feature is off.
 */
class EnsureEInvoicingIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        abort_unless($property instanceof Property && Feature::for($property)->active(EInvoicing::class), 404);

        return $next($request);
    }
}
