<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The one error shape of the device and integration API: `{ "error": { "code": "...", "message": "..." } }`.
 * Codes are stable; `message` is safe to show. `details` adds fields inside `error`. See docs/28-api-access/01-api-tokens.md.
 */
class ApiError
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $details
     */
    public static function response(string $code, string $message, int $status, array $headers = [], array $details = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, ...$details]], $status, $headers);
    }
}
