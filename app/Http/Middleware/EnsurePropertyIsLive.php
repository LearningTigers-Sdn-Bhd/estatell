<?php

namespace App\Http\Middleware;

use App\Enums\PropertyStatus;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps operational modules closed until the property is Live. Runs after `property.access`, which
 * already sends suspended properties to their own page.
 */
class EnsurePropertyIsLive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        if (! $property instanceof Property || $property->status === PropertyStatus::Live) {
            return $next($request);
        }

        return $property->status === PropertyStatus::PendingApproval
            ? to_route('onboarding.go-live.show', ['property' => $property->code])
            : to_route('onboarding.basics.edit', ['property' => $property->code]);
    }
}
