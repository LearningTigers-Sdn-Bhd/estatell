<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The one class that talks to Curlec, which documents the Razorpay API. Order notes hold only the app name and the hold
 * token, because guest identity data never goes to the gateway. With no secret set, every signature fails.
 * See docs/29-booking-engine/04-payment.md#gateway-code.
 */
class CurlecGateway
{
    public function keyId(): string
    {
        return (string) config('services.curlec.key_id');
    }

    public function account(): string
    {
        return (string) config('services.curlec.account');
    }

    /**
     * Create an order and return its ID. `$amountInSubunits` is the smallest currency unit, such as sen.
     *
     * @throws CurlecRequestFailed
     */
    public function createOrder(int $amountInSubunits, string $currency, string $receipt, string $holdToken): string
    {
        try {
            $response = Http::withBasicAuth($this->keyId(), (string) config('services.curlec.key_secret'))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post(rtrim((string) config('services.curlec.base_url'), '/').'/orders', [
                    'amount' => $amountInSubunits,
                    'currency' => $currency,
                    'receipt' => $receipt,
                    'notes' => ['app' => 'estatell', 'hold' => $holdToken],
                ]);
        } catch (ConnectionException) {
            throw new CurlecRequestFailed('Curlec could not be reached.');
        }

        $orderId = $response->json('id');

        if (! $response->successful() || ! is_string($orderId) || $orderId === '') {
            throw new CurlecRequestFailed('Curlec refused the order with status '.$response->status().'.');
        }

        return $orderId;
    }

    /**
     * Ask Curlec to refund part or all of a payment, and return the refund's ID. `$receipt` is Curlec's idempotency key, so
     * a repeat returns null: the first request reached Curlec, and the webhook will report it. `$refundId` goes in the notes,
     * so a webhook that arrives before this answer still finds its row.
     *
     * @throws CurlecRequestFailed Worth asking again: Curlec is unreachable, its balance is too low, or the payment is busy.
     * @throws CurlecRefundRefused Asking again will not help.
     */
    public function refund(string $paymentId, int $amountInSubunits, string $receipt, int $refundId): ?string
    {
        try {
            $response = Http::withBasicAuth($this->keyId(), (string) config('services.curlec.key_secret'))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post(rtrim((string) config('services.curlec.base_url'), '/').'/payments/'.rawurlencode($paymentId).'/refund', [
                    'amount' => $amountInSubunits,
                    'receipt' => $receipt,
                    'notes' => ['app' => 'estatell', 'refund' => (string) $refundId],
                ]);
        } catch (ConnectionException) {
            throw new CurlecRequestFailed('Curlec could not be reached.');
        }

        $curlecRefundId = $response->json('id');

        if ($response->successful() && is_string($curlecRefundId) && $curlecRefundId !== '') {
            return $curlecRefundId;
        }

        $description = (string) $response->json('error.description');

        if (Str::contains($description, 'duplicate', ignoreCase: true)) {
            return null;
        }

        if ($response->serverError() || in_array($response->status(), [401, 429], true) || Str::contains($description, ['balance', 'in progress', 'already running'], ignoreCase: true)) {
            throw new CurlecRequestFailed($description !== '' ? $description : 'Curlec answered the refund with status '.$response->status().'.');
        }

        throw new CurlecRefundRefused($description !== '' ? $description : 'Curlec refused the refund with status '.$response->status().'.');
    }

    /**
     * Whether the signature the guest's browser brought back was made by Curlec for this order and payment.
     */
    public function verifySignature(string $orderId, string $paymentId, string $signature): bool
    {
        return $this->matches($orderId.'|'.$paymentId, (string) config('services.curlec.key_secret'), $signature);
    }

    /**
     * Whether a webhook's signature matches its raw body.
     */
    public function verifyWebhook(string $rawBody, string $signature): bool
    {
        return $this->matches($rawBody, (string) config('services.curlec.webhook_secret'), $signature);
    }

    private function matches(string $message, string $secret, string $signature): bool
    {
        return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $message, $secret), $signature);
    }
}
