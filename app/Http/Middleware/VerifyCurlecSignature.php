<?php

namespace App\Http\Middleware;

use App\Services\Payments\CurlecGateway;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts a Curlec webhook only when `X-Razorpay-Signature` is the HMAC-SHA256 of the raw body with the webhook secret.
 * A bad signature answers 400 and saves nothing. With no secret set, every webhook is refused.
 */
class VerifyCurlecSignature
{
    public function __construct(private CurlecGateway $gateway) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->gateway->verifyWebhook($request->getContent(), (string) $request->header('x-razorpay-signature')), 400);

        return $next($request);
    }
}
