<?php

namespace App\Http\Middleware;

use App\Features\Concierge;
use App\Models\Property;
use App\Services\Concierge\ConciergeStays;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the Concierge routes, which have no login. A route with `{token}` resolves the stay of that link and keeps it in
 * `concierge.stay`; a route with `{property}` is the lobby. Both answer 404, the same as for a missing page, while the
 * link is unknown or expired, the property is suspended or its Concierge feature is off. A write works only on a Confirmed
 * or CheckedIn stay, unless the route says `concierge:checked-out`.
 */
class EnsureConciergeIsActive
{
    public function __construct(private ConciergeStays $stays) {}

    public function handle(Request $request, Closure $next, string ...$allow): Response
    {
        $property = $request->route('property');
        $token = $request->route('token');

        if (is_string($token)) {
            $stay = $this->stays->byToken($token, forReading: $request->isMethodSafe() || in_array('checked-out', $allow, true));
            abort_if($stay === null, 404);

            $property = $stay->property()->first();
            $request->attributes->set('concierge.stay', $stay);
        }

        abort_unless($property instanceof Property && $property->suspended_at === null && Feature::for($property)->active(Concierge::class), 404);

        return $next($request);
    }
}
