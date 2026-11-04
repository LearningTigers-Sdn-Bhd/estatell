<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The one error shape of the device and integration API: `{ "error": { "code": "...", "message": "..." } }`.
 * Codes are stable; `message` is safe to show. See docs/28-api-access/01-api-tokens-and-kiosk-pairing.md.
 */
class ApiError
{
    /**
     * @param  array<string, string>  $headers
     */
    public static function response(string $code, string $message, int $status, array $headers = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }
}
