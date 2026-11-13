<?php

namespace App\Services\Webhooks;

/**
 * Signs an outgoing webhook body. The signature is `sha256=` and the hex HMAC-SHA256 of `{timestamp}.{body}`. See
 * docs/32-webhooks/01-outgoing-webhooks.md#request.
 */
class WebhookSignature
{
    public function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public function matches(string $secret, int $timestamp, string $body, string $signature): bool
    {
        return hash_equals($this->sign($secret, $timestamp, $body), $signature);
    }
}
