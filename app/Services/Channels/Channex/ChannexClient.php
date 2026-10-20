<?php

namespace App\Services\Channels\Channex;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Thin HTTP client for the Channex API. It holds no state and writes nothing to the database. Channex allows 10
 * restriction/price and 10 availability calls a minute per property. The limiter allows 5 per endpoint per property:
 * any 60 seconds touches at most two clock minutes, so it never passes 10.
 */
class ChannexClient
{
    public const CALLS_PER_MINUTE = 5;

    public const DEFAULT_RETRY_AFTER = 60;

    /** @var list<string> */
    private const LIMITED_PATHS = ['/availability', '/restrictions'];

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        $this->spendBudget($path, $body);

        return $this->send('post', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body = []): array
    {
        return $this->send('put', $path, $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $path): array
    {
        return $this->send('delete', $path);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ChannexRetryLater
     * @throws ChannexRequestFailed
     */
    private function send(string $method, string $path, array $data = []): array
    {
        try {
            $response = $this->request()->{$method}($path, $data ?: null);
        } catch (ConnectionException $exception) {
            throw new ChannexRetryLater('Channex could not be reached: '.$exception->getMessage(), self::DEFAULT_RETRY_AFTER);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new ChannexRetryLater(
                "Channex answered {$response->status()} for {$path}.",
                $this->retryAfter($response),
                $response->status(),
            );
        }

        if ($response->failed()) {
            $errors = $response->json('errors');

            throw new ChannexRequestFailed(
                "Channex refused {$path} with {$response->status()}.",
                $response->status(),
                is_array($errors) ? $errors : [],
            );
        }

        return $response->json() ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('services.channex.base_url'))
            ->withHeaders(['user-api-key' => (string) config('services.channex.api_key')])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(30);
    }

    /**
     * Count one call against the property's budget for the ARI endpoints. Other calls are never limited.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws ChannexRetryLater
     */
    private function spendBudget(string $path, array $body): void
    {
        $propertyId = $body['values'][0]['property_id'] ?? null;

        if (! in_array($path, self::LIMITED_PATHS, true) || ! is_string($propertyId)) {
            return;
        }

        $key = "channex:ari:{$propertyId}:{$path}";

        if (RateLimiter::tooManyAttempts($key, self::CALLS_PER_MINUTE)) {
            throw new ChannexRetryLater(
                "Channex budget for {$path} is used up for this minute.",
                max(1, RateLimiter::availableIn($key)),
            );
        }

        RateLimiter::hit($key, 60);
    }

    private function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) && (int) $header > 0 ? (int) $header : self::DEFAULT_RETRY_AFTER;
    }
}
