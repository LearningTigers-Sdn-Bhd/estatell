<?php

namespace App\Services\Pos\Loyverse;

use App\Enums\PosConnectionStatus;
use App\Models\PosConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The Loyverse REST API (v1.0) for one connection, over OAuth 2.0. Verified against https://developer.loyverse.com/docs/.
 *
 * - Authorize `{base}/oauth/authorize`, token `{base}/oauth/token` (form body); scopes are separated by a space.
 * - A token lasts 12 hours. It is refreshed under a per-connection cache lock when it has 5 minutes left, or once on a 401.
 *   A refresh that Loyverse refuses marks the connection Error and throws `LoyverseException::reauthRequired()`.
 * - A 429 throws `LoyverseException::rateLimited()`. Lists follow Loyverse's `cursor` (up to 250 a page).
 * - A webhook created with an OAuth token is signed with the app's client secret (see `VerifyLoyverseSignature`).
 * Stateless apart from the refreshed tokens it saves on the connection.
 */
class LoyverseClient
{
    /** The scopes Estatell asks for. Loyverse has no webhook scope: an OAuth token may call `/webhooks`. */
    public const array SCOPES = ['CUSTOMERS_READ', 'CUSTOMERS_WRITE', 'RECEIPTS_READ', 'STORES_READ', 'PAYMENT_TYPES_READ', 'MERCHANT_READ'];

    private const int REFRESH_BUFFER_MINUTES = 5;

    public function isConfigured(): bool
    {
        return filled(config('services.loyverse.client_id')) && filled(config('services.loyverse.client_secret'));
    }

    public function redirectUri(): string
    {
        return (string) (config('services.loyverse.redirect_uri') ?: route('webhooks.loyverse.oauth-callback'));
    }

    public function authorizeUrl(string $state): string
    {
        return $this->base().'/oauth/authorize?'.http_build_query([
            'client_id' => config('services.loyverse.client_id'),
            'scope' => implode(' ', self::SCOPES),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Trade the authorization code for tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post($this->base().'/oauth/token', [
            'client_id' => config('services.loyverse.client_id'),
            'client_secret' => config('services.loyverse.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
            'grant_type' => 'authorization_code',
        ]);

        return $this->tokens($response);
    }

    /**
     * The merchant id behind an access token, before the connection is saved.
     */
    public function merchantIdFor(string $accessToken): string
    {
        $response = $this->http($accessToken)->get('/merchant/');

        return (string) $this->ok($response)->json('id');
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function stores(PosConnection $connection): array
    {
        return $this->list($connection, '/stores', 'stores');
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function paymentTypes(PosConnection $connection): array
    {
        return $this->list($connection, '/payment_types', 'payment_types');
    }

    /**
     * Create a customer, or update the one with `$id`. Returns the customer id. When Loyverse no longer has `$id` (a merchant
     * deleted the customer by hand), a new customer is created, so the guest is not stuck without one.
     */
    public function saveCustomer(PosConnection $connection, ?string $id, string $name, string $note): string
    {
        $response = $this->send($connection, 'POST', '/customers', array_filter(['id' => $id, 'name' => $name, 'note' => $note], fn (?string $value): bool => $value !== null));

        if ($id !== null && $response->status() === 404) {
            $response = $this->send($connection, 'POST', '/customers', ['name' => $name, 'note' => $note]);
        }

        return (string) $this->ok($response)->json('id');
    }

    /**
     * Delete a customer. True when it is gone (a 404 counts); false when Loyverse refuses with a 4xx, so the caller renames it.
     * A 5xx throws, so the job retries a server error instead of renaming a customer that may still be deletable.
     */
    public function deleteCustomer(PosConnection $connection, string $id): bool
    {
        $response = $this->send($connection, 'DELETE', '/customers/'.$id);

        if ($response->serverError()) {
            throw LoyverseException::failed($response->status());
        }

        return $response->successful() || $response->status() === 404;
    }

    /**
     * The id of the `receipts.update` webhook for this URL, created when it does not exist yet.
     */
    public function ensureReceiptsWebhook(PosConnection $connection, string $url): string
    {
        foreach ((array) $this->ok($this->send($connection, 'GET', '/webhooks/'))->json() as $webhook) {
            if (is_array($webhook) && ($webhook['type'] ?? null) === 'receipts.update' && ($webhook['url'] ?? null) === $url && isset($webhook['id'])) {
                return (string) $webhook['id'];
            }
        }

        $response = $this->send($connection, 'POST', '/webhooks/', ['url' => $url, 'type' => 'receipts.update', 'status' => 'ENABLED']);

        return (string) $this->ok($response)->json('id');
    }

    public function deleteWebhook(PosConnection $connection, string $id): void
    {
        $this->send($connection, 'DELETE', '/webhooks/'.$id);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function list(PosConnection $connection, string $path, string $key): array
    {
        $rows = [];
        $cursor = null;

        do {
            $body = $this->ok($this->send($connection, 'GET', $path, query: array_filter(['limit' => 250, 'cursor' => $cursor])))->json();

            foreach ($body[$key] ?? [] as $row) {
                if (($row['deleted_at'] ?? null) === null) {
                    $rows[] = ['id' => (string) $row['id'], 'name' => (string) ($row['name'] ?? $row['id'])];
                }
            }

            $cursor = $body['cursor'] ?? null;
        } while ($cursor !== null);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $query
     */
    private function send(PosConnection $connection, string $method, string $path, array $data = [], array $query = []): Response
    {
        $connection = $this->fresh($connection);
        $response = $this->call($connection, $method, $path, $data, $query);

        if ($response->status() === 401) {
            $connection = $this->fresh($connection, force: true);
            $response = $this->call($connection, $method, $path, $data, $query);
        }

        if ($response->status() === 429) {
            throw LoyverseException::rateLimited((int) ($response->header('Retry-After') ?: 30));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $query
     */
    private function call(PosConnection $connection, string $method, string $path, array $data, array $query): Response
    {
        return $this->http((string) $connection->access_token)->send($method, $path, $method === 'GET' ? ['query' => $query] : ['json' => $data]);
    }

    private function fresh(PosConnection $connection, bool $force = false): PosConnection
    {
        if (! $force && $this->isFresh($connection)) {
            return $connection;
        }

        $previousToken = $connection->access_token;

        return Cache::lock('loyverse-refresh:'.$connection->id, 15)->block(10, function () use ($connection, $force, $previousToken): PosConnection {
            $connection->refresh();

            // Another request refreshed while this one waited for the lock, so its new token is the answer. Two
            // concurrent 401s then never spend the same refresh token twice.
            if ($connection->access_token !== $previousToken && $this->isFresh($connection)) {
                return $connection;
            }

            if ($connection->status === PosConnectionStatus::Disconnected || $connection->refresh_token === null) {
                throw LoyverseException::reauthRequired();
            }

            if (! $force && $this->isFresh($connection)) {
                return $connection;
            }

            $response = Http::asForm()->acceptJson()->timeout(15)->post($this->base().'/oauth/token', [
                'client_id' => config('services.loyverse.client_id'),
                'client_secret' => config('services.loyverse.client_secret'),
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ]);

            if ($response->failed()) {
                if (in_array($response->status(), [400, 401], true)) {
                    $connection->forceFill(['status' => PosConnectionStatus::Error, 'last_error' => 'Loyverse access was revoked or expired. Connect Loyverse again.'])->save();

                    throw LoyverseException::reauthRequired();
                }

                throw LoyverseException::failed($response->status());
            }

            $tokens = $this->tokens($response);
            $connection->forceFill([
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'token_expires_at' => now()->addSeconds($tokens['expires_in']),
            ])->save();

            return $connection;
        });
    }

    private function isFresh(PosConnection $connection): bool
    {
        return $connection->access_token !== null
            && $connection->token_expires_at !== null
            && $connection->token_expires_at->gt(now()->addMinutes(self::REFRESH_BUFFER_MINUTES));
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    private function tokens(Response $response): array
    {
        $body = $this->ok($response)->json();

        return [
            'access_token' => (string) $body['access_token'],
            'refresh_token' => (string) $body['refresh_token'],
            'expires_in' => (int) ($body['expires_in'] ?? 43200),
        ];
    }

    private function ok(Response $response): Response
    {
        if ($response->status() === 429) {
            throw LoyverseException::rateLimited((int) ($response->header('Retry-After') ?: 30));
        }

        if ($response->failed()) {
            throw LoyverseException::failed($response->status());
        }

        return $response;
    }

    private function http(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)->acceptJson()->timeout(15)->baseUrl($this->base().'/v1.0');
    }

    private function base(): string
    {
        return rtrim((string) config('services.loyverse.base_url', 'https://api.loyverse.com'), '/');
    }
}
