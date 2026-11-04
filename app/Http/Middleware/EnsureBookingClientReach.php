<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiError;
use App\Models\BookingClient;
use App\Services\BookingEngine\BookingClientReach;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the property in the path (`{code}` or `{slug}`) and checks that the booking client can reach it. A property the
 * client cannot reach answers 404, so a client cannot learn that it exists. Controllers read the `booking_property`
 * attribute and never take a property from the body. See docs/28-api-access/04-booking-engine-microsite.md.
 */
class EnsureBookingClientReach
{
    public function __construct(private BookingClientReach $reach) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var BookingClient $client */
        $client = $request->attributes->get('booking_client');
        $code = $request->route('code');
        $property = is_string($code)
            ? $this->reach->propertyByCode($client, $code)
            : $this->reach->propertyBySlug($client, (string) $request->route('slug'));

        if ($property === null) {
            return ApiError::response('property_not_found', __('We could not find that property.'), 404);
        }

        $request->attributes->set('booking_property', $property);

        return $next($request);
    }
}
