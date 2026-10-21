<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts a MillionSend webhook only when it carries a valid Standard Webhooks signature: base64 HMAC-SHA256 of
 * `<id>.<timestamp>.<raw body>`, keyed with the base64 part of the `whsec_` secret. The header holds one or more
 * space-separated `v1,<signature>` values (more than one while the key rotates), and any match passes. A timestamp
 * more than five minutes off is a replay. With no secret set, every webhook is refused.
 */
class VerifyMillionSendSignature
{
    private const int TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.millionsend.webhook_secret');
        $id = (string) $request->header('webhook-id');
        $timestamp = (string) $request->header('webhook-timestamp');
        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);

        if ($key === false || $key === '' || $id === '' || ! ctype_digit($timestamp) || abs(now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            abort(401);
        }

        $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$request->getContent(), $key, true));
        $signatures = array_map(fn (string $signature): string => str_starts_with($signature, 'v1,') ? substr($signature, 3) : '', explode(' ', trim((string) $request->header('webhook-signature'))));

        abort_unless(array_any($signatures, fn (string $signature): bool => $signature !== '' && hash_equals($expected, $signature)), 401);

        return $next($request);
    }
}
