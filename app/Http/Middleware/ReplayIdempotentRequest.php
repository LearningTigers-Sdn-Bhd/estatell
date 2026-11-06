<?php

namespace App\Http\Middleware;

use App\Actions\ApiAccess\StoreIdempotentResponse;
use App\Models\BookingClient;
use App\Models\Kiosk;
use App\Models\Property;
use App\Services\ApiAccess\IdempotentResponses;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.idempotent`: honours the `Idempotency-Key` header on a write. The first answer (anything below 500) is stored for
 * 24 hours and sent again, with `Idempotent-Replayed: true`, for the same owner, key and path. The owner is a kiosk or a
 * booking client. A lock makes two requests with one key run one after the other, so the second one replays. A request
 * without a key just runs. Put it after `api.token`, which sets the owner, and after `api.booking.reach`, which sets the
 * property of a booking route. The body of a request is never stored; the answer is.
 */
class ReplayIdempotentRequest
{
    private const int MAX_KEY_LENGTH = 100;

    public function __construct(private IdempotentResponses $responses, private StoreIdempotentResponse $store) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $owner = $request->attributes->get('kiosk') ?? $request->attributes->get('booking_client');

        if (! is_string($key) || $key === '' || strlen($key) > self::MAX_KEY_LENGTH || ! ($owner instanceof Kiosk || $owner instanceof BookingClient)) {
            return $next($request);
        }

        $path = $request->path();

        return Cache::lock('api-idempotency:'.class_basename($owner).':'.$owner->id.':'.sha1($key.'|'.$path), 30)->block(15, function () use ($request, $next, $owner, $key, $path): Response {
            $stored = $this->responses->find($owner, $key, $path);

            if ($stored !== null) {
                return new JsonResponse($stored->body, $stored->status, ['Idempotent-Replayed' => 'true'], 0, true);
            }

            $response = $next($request);

            if ($response instanceof JsonResponse && $response->getStatusCode() < 500) {
                ($this->store)($owner, $this->propertyId($request, $owner), $key, $path, $response);
            }

            return $response;
        });
    }

    private function propertyId(Request $request, Kiosk|BookingClient $owner): ?int
    {
        if ($owner instanceof Kiosk) {
            return $owner->property_id;
        }

        $property = $request->attributes->get('booking_property');

        return $property instanceof Property ? $property->id : null;
    }
}
