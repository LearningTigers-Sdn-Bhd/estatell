<?php

namespace App\Http\Middleware;

use App\Models\Property;
use App\Services\Commissions\PropertyPayouts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to the Payouts routes while the property has no commission service on and no voucher it may see.
 * See docs/30-commission-fees/06-property-payouts.md#permission.
 */
class EnsurePayoutsAreShown
{
    public function __construct(private PropertyPayouts $payouts) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        abort_unless($property instanceof Property && $this->payouts->isShown($property), 404);

        return $next($request);
    }
}
