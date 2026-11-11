<?php

namespace App\Services\EInvoice;

use App\Models\PropertyEInvoiceSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MyInvoisClient
{
    private string $baseUrl;

    private string $tin;

    private string $clientId;

    private string $clientSecret;

    private string $environment;

    public function __construct(
        private PropertyEInvoiceSetting $setting
    ) {
        $this->environment = $setting->api_environment->value;
        $this->baseUrl = $setting->api_environment->apiUrl();

        $tin = $setting->supplier_tin ?: $setting->property->tin;
        if (empty($tin)) {
            throw new RuntimeException('Property tax identification number (TIN) is missing.');
        }

        if (empty($setting->client_id) || empty($setting->client_secret)) {
            throw new RuntimeException('Property MyInvois API credentials (Client ID / Client Secret) are not configured.');
        }

        $this->tin = $tin;
        $this->clientId = $setting->client_id;
        $this->clientSecret = $setting->client_secret;
    }

    /**
     * Submits an array of base64 UBL documents to LHDN MyInvois.
     *
     * @param  list<array<string, mixed>>  $documents
     * @return array<string, mixed>
     */
    public function submitDocuments(array $documents): array
    {
        return $this->post('/api/v1.0/documentsubmissions', [
            'documents' => $documents,
        ]);
    }

    /**
     * Checks the processing status of a batch submission.
     *
     * @return array<string, mixed>
     */
    public function getSubmission(string $submissionUid): array
    {
        return $this->get("/api/v1.0/documentsubmissions/{$submissionUid}");
    }

    /**
     * Retrieves full validation details and longId for a document.
     *
     * @return array<string, mixed>
     */
    public function getDocumentDetails(string $uuid): array
    {
        return $this->get("/api/v1.0/documents/{$uuid}/details");
    }

    /**
     * Cancels an already validated document within the 72-hour window.
     *
     * @return array<string, mixed>
     */
    public function cancelDocument(string $uuid, string $reason): array
    {
        return $this->put("/api/v1.0/documents/state/{$uuid}/state", [
            'status' => 'cancelled',
            'reason' => $reason,
        ]);
    }

    /**
     * Validates a buyer's TIN against LHDN database.
     *
     * @return array<string, mixed>
     */
    public function validateTin(string $tin, string $idType, string $idValue): array
    {
        return $this->get("/api/v1.0/taxpayer/validate/{$tin}", [
            'idType' => $idType,
            'idValue' => $idValue,
        ]);
    }

    /**
     * The one TIN LHDN holds for an ID (`NRIC`, `PASSPORT`, `BRN`, `ARMY`). Throws with status 404 when none matches,
     * and 400 when the ID is malformed or several TINs match.
     *
     * @return array<string, mixed>
     */
    public function searchTin(string $idType, string $idValue): array
    {
        return $this->get('/api/v1.0/taxpayer/search/tin', [
            'idType' => $idType,
            'idValue' => $idValue,
        ]);
    }

    public function getAccessToken(): string
    {
        $cacheKey = "myinvois_token_{$this->tin}_{$this->environment}";

        return Cache::remember($cacheKey, 3000, function () {
            $response = $this->send(fn () => Http::asForm()
                ->timeout(15)
                ->post("{$this->baseUrl}/connect/token", [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'InvoicingAPI',
                ]));

            if (! $response->successful()) {
                throw new MyInvoisApiException(
                    'Failed to authenticate with MyInvois: '.($response->json('error_description') ?: $response->body()),
                    $response->status(),
                    $response->json() ?: $response->body()
                );
            }

            return (string) $response->json('access_token');
        });
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $token = $this->getAccessToken();
        $response = $this->send(fn () => Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->get("{$this->baseUrl}{$path}", $query));

        return $this->handleResponse($response);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $token = $this->getAccessToken();
        $response = $this->send(fn () => Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->post("{$this->baseUrl}{$path}", $payload));

        return $this->handleResponse($response);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function put(string $path, array $payload): array
    {
        $token = $this->getAccessToken();
        $response = $this->send(fn () => Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->put("{$this->baseUrl}{$path}", $payload));

        return $this->handleResponse($response);
    }

    /**
     * Runs one HTTP call. A timeout or a dropped connection becomes an API error with status 0, so callers record
     * "could not reach LHDN" instead of failing the page.
     *
     * @param  callable(): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException $exception) {
            throw new MyInvoisApiException('Could not reach MyInvois: '.$exception->getMessage(), 0, null, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(Response $response): array
    {
        if (! $response->successful()) {
            throw new MyInvoisApiException(
                'MyInvois API Error: '.($response->json('error.message') ?: $response->body()),
                $response->status(),
                $response->json() ?: $response->body()
            );
        }

        return (array) ($response->json() ?? []);
    }
}
