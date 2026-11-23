<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks `X-Loyverse-Signature` on a Loyverse webhook: the lowercase hex HMAC-SHA1 of the raw body, keyed with the app's
 * client secret (webhooks created with an OAuth token are signed; verified against developer.loyverse.com/docs). The
 * comparison is constant time. A missing secret or header, or a wrong digest, answers 401 and nothing is processed.
 */
class VerifyLoyverseSignature
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.loyverse.client_secret');
        $signature = (string) $request->header('X-Loyverse-Signature');

        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha1', $request->getContent(), $secret), strtolower($signature))) {
            abort(401);
        }

        return $next($request);
    }
}
