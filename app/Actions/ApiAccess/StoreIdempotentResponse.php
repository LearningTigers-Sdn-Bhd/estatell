<?php

namespace App\Actions\ApiAccess;

use App\Models\AiConnection;
use App\Models\ApiIdempotencyKey;
use App\Models\BookingClient;
use App\Models\Kiosk;
use App\Models\PosApiClient;
use Illuminate\Http\JsonResponse;

class StoreIdempotentResponse
{
    /**
     * Keep the first answer to a key. An expired row for the same key is replaced. A kiosk has one property; a booking
     * client passes the property in the path, if there is one.
     */
    public function __invoke(Kiosk|BookingClient|PosApiClient|AiConnection $owner, ?int $propertyId, string $key, string $path, JsonResponse $response): void
    {
        $row = ApiIdempotencyKey::query()->forOwner($owner)->where('key', $key)->where('path', $path)->first()
            ?? new ApiIdempotencyKey(['key' => $key, 'path' => $path]);
        $row->property_id = $propertyId;
        $row->kiosk_id = $owner instanceof Kiosk ? $owner->id : null;
        $row->booking_client_id = $owner instanceof BookingClient ? $owner->id : null;
        $row->pos_api_client_id = $owner instanceof PosApiClient ? $owner->id : null;
        $row->ai_connection_id = $owner instanceof AiConnection ? $owner->id : null;
        $row->fill(['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()]);
        $row->created_at = now();
        $row->save();
    }
}
