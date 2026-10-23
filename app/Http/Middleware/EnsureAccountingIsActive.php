<?php

namespace App\Http\Middleware;

use App\Features\AccountingIntegration;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to accounting routes while the property's Accounting integration feature is off.
 */
class EnsureAccountingIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        abort_unless($property instanceof Property && Feature::for($property)->active(AccountingIntegration::class), 404);

        return $next($request);
    }
}
