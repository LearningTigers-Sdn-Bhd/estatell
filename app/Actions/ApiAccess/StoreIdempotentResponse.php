<?php

namespace App\Actions\ApiAccess;

use App\Models\ApiIdempotencyKey;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;

class StoreIdempotentResponse
{
    /**
     * Keep the first answer to a key. An expired row for the same key is replaced.
     */
    public function __invoke(ApiToken $token, string $key, string $path, JsonResponse $response): void
    {
        $row = ApiIdempotencyKey::query()->where('api_token_id', $token->id)->where('key', $key)->where('path', $path)->first()
            ?? new ApiIdempotencyKey(['key' => $key, 'path' => $path]);
        $row->property_id = $token->property_id;
        $row->api_token_id = $token->id;
        $row->fill(['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()]);
        $row->created_at = now();
        $row->save();
    }
}
