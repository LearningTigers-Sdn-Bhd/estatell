<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts a Channex webhook only when its HMAC-SHA256 signature matches `<timestamp>.<raw body>` with the shared
 * secret. The signature header holds one signature, or two while Channex rotates the key, and any match passes. A
 * timestamp more than five minutes off is a replay. With no secret set, every webhook is refused.
 */
class VerifyChannexSignature
{
    private const int TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.channex.webhook_secret');
        $timestamp = (string) $request->header('x-hmac-timestamp');

        if ($secret === '' || ! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            abort(401);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        $signatures = array_map('trim', explode(',', (string) $request->header('x-hmac-signature-256')));

        abort_unless(array_any($signatures, fn (string $signature): bool => hash_equals($expected, strtolower($signature))), 401);

        return $next($request);
    }
}
