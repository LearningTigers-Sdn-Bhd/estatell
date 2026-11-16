<?php

namespace App\Http\Middleware;

use App\Features\BedRoomTypes;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to the bed routes while the property's BedRoomTypes feature is off. Pennant's EnsureFeaturesAreActive is
 * not used here because its inactive handler is global and belongs to the Channels upsell.
 */
class EnsureBedsAreActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        abort_unless($property instanceof Property && Feature::for($property)->active(BedRoomTypes::class), 404);

        return $next($request);
    }
}
