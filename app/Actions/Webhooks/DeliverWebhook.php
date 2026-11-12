<?php

namespace App\Actions\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookPayload;
use App\Services\Webhooks\WebhookSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Send one try of a delivery, record it, and decide what comes next: delivered, refused (4xx, never tried again),
 * another try later, or failed after the last try. A failed try marks the endpoint as failing and may turn it off.
 * A delivery that is not waiting for a try is left alone. A test event is sent even while the endpoint is
 * off, so an admin can check a URL before turning it on. See docs/32-webhooks/01-outgoing-webhooks.md#retries.
 */
class DeliverWebhook
{
    public const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private WebhookPayload $payload,
        private WebhookSignature $signature,
        private DisableFailingWebhookEndpoint $disableFailingEndpoint,
    ) {}

    public function __invoke(WebhookDelivery $delivery): WebhookDeliveryStatus
    {
        if (! in_array($delivery->status, [WebhookDeliveryStatus::Pending, WebhookDeliveryStatus::Retrying], true)) {
            return $delivery->status;
        }

        $delivery->loadMissing('endpoint');
        $endpoint = $delivery->endpoint;

        if (! $endpoint->is_active && ! $delivery->is_test) {
            $delivery->update(['status' => WebhookDeliveryStatus::Failed, 'error' => __('The endpoint was off.'), 'next_attempt_at' => null]);

            return WebhookDeliveryStatus::Failed;
        }

        $attempt = $delivery->attempts + 1;
        $body = $this->payload->body($delivery->payload);
        $timestamp = now()->getTimestamp();
        $started = hrtime(true);
        $response = null;
        $error = null;

        try {
            $response = $this->send($endpoint, $delivery, $body, $timestamp);
        } catch (ConnectionException $exception) {
            $error = Str::limit($exception->getMessage(), 250);
        }

        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $responseStatus = $response?->status();
        $error ??= $responseStatus !== null && ($responseStatus < 200 || $responseStatus > 299) ? 'HTTP '.$responseStatus : null;

        $delivery->tries()->create([
            'attempt' => $attempt,
            'response_status' => $responseStatus,
            'error' => $error,
            'duration_ms' => $durationMs,
            'attempted_at' => now(),
        ]);

        $status = $this->nextStatus($responseStatus, $attempt);
        $nextAttemptAt = $status === WebhookDeliveryStatus::Retrying ? now()->addSeconds(WebhookDelivery::BACKOFF_SECONDS[$attempt - 1]) : null;

        $delivery->update([
            'status' => $status,
            'attempts' => $attempt,
            'response_status' => $responseStatus,
            'error' => $error,
            'next_attempt_at' => $nextAttemptAt,
            'delivered_at' => $status === WebhookDeliveryStatus::Delivered ? now() : null,
        ]);

        if ($status === WebhookDeliveryStatus::Delivered) {
            $endpoint->update(['failing_since' => null]);

            return $status;
        }

        if ($nextAttemptAt !== null) {
            DeliverWebhookJob::dispatch($delivery->id)->delay($nextAttemptAt);
        }

        $this->markFailing($endpoint, $responseStatus);

        return $status;
    }

    private function send(WebhookEndpoint $endpoint, WebhookDelivery $delivery, string $body, int $timestamp): Response
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::TIMEOUT_SECONDS)
            ->withoutRedirecting()
            ->withUserAgent('Estatell-Webhooks/1')
            ->withHeaders([
                'X-Estatell-Event' => $delivery->event->value,
                'X-Estatell-Delivery' => $delivery->event_id,
                'X-Estatell-Timestamp' => (string) $timestamp,
                'X-Estatell-Signature' => $this->signature->sign($endpoint->secret, $timestamp, $body),
            ])
            ->withBody($body, 'application/json')
            ->post($endpoint->url);
    }

    /**
     * 408 and 429 ask to try again later, so they are retried like a 5xx. A redirect is not followed and counts as
     * refused, like any other 4xx.
     */
    private function nextStatus(?int $responseStatus, int $attempt): WebhookDeliveryStatus
    {
        if ($responseStatus !== null && $responseStatus >= 200 && $responseStatus <= 299) {
            return WebhookDeliveryStatus::Delivered;
        }

        if ($responseStatus !== null && $responseStatus >= 300 && $responseStatus <= 499 && ! in_array($responseStatus, [408, 429], true)) {
            return WebhookDeliveryStatus::Refused;
        }

        return $attempt >= WebhookDelivery::MAX_ATTEMPTS ? WebhookDeliveryStatus::Failed : WebhookDeliveryStatus::Retrying;
    }

    private function markFailing(WebhookEndpoint $endpoint, ?int $responseStatus): void
    {
        WebhookEndpoint::query()->whereKey($endpoint->id)->whereNull('failing_since')->update(['failing_since' => now()]);
        $endpoint->refresh();

        $reason = $responseStatus === null
            ? __('No response for :days days', ['days' => WebhookEndpoint::DISABLE_AFTER_DAYS])
            : __('HTTP :status for :days days', ['status' => $responseStatus, 'days' => WebhookEndpoint::DISABLE_AFTER_DAYS]);

        ($this->disableFailingEndpoint)($endpoint, $reason);
    }
}
