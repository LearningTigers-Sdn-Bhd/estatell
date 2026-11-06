<?php

namespace App\Actions\ApiAccess;

use App\Models\ApiIdempotencyKey;
use App\Models\Kiosk;
use Illuminate\Http\JsonResponse;

class StoreIdempotentResponse
{
    /**
     * Keep the first answer to a key. An expired row for the same key is replaced.
     */
    public function __invoke(Kiosk $kiosk, string $key, string $path, JsonResponse $response): void
    {
        $row = ApiIdempotencyKey::query()->where('kiosk_id', $kiosk->id)->where('key', $key)->where('path', $path)->first()
            ?? new ApiIdempotencyKey(['key' => $key, 'path' => $path]);
        $row->property_id = $kiosk->property_id;
        $row->kiosk_id = $kiosk->id;
        $row->fill(['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()]);
        $row->created_at = now();
        $row->save();
    }
}
