<?php

use App\Enums\ChannelProvider;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\Channex\ChannexClient;
use App\Services\Channels\Channex\ChannexDriver;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    config([
        'services.channex.base_url' => 'https://staging.channex.io/api/v1',
        'services.channex.api_key' => 'secret-key',
    ]);
    RateLimiter::clear('channex:ari:prop-1:/restrictions');
    RateLimiter::clear('channex:ari:prop-1:/availability');
    RateLimiter::clear('channex:ari:prop-2:/restrictions');
});

function ariBody(string $propertyId = 'prop-1'): array
{
    return ['values' => [['property_id' => $propertyId, 'date' => '2026-11-01', 'rate' => 10000]]];
}

test('the client sends the api key to the configured base url', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    app(ChannexClient::class)->get('/channels/list');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://staging.channex.io/api/v1/channels/list'
        && $request->header('user-api-key') === ['secret-key']);
});

test('the driver reads one adapter descriptor', function () {
    Http::fake(['*' => Http::response(['data' => ['code' => 'BookingCom', 'params' => []]])]);

    $adapter = app(ChannelDriverManager::class)->forProvider(ChannelProvider::Channex)->adapter('BookingCom');

    expect($adapter['code'])->toBe('BookingCom');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://staging.channex.io/api/v1/channels/adapter?code=BookingCom');
});

test('the manager returns the channex driver', function () {
    expect(app(ChannelDriverManager::class)->forProvider(ChannelProvider::Channex))->toBeInstanceOf(ChannexDriver::class);
});

test('driver calls that belong to later commits throw', function () {
    app(ChannelDriverManager::class)->forProvider(ChannelProvider::Channex)->activate('channel-1');
})->throws(LogicException::class, 'Not built yet');

test('the sixth restrictions call in a minute is held back and nothing is sent', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    $client = app(ChannexClient::class);

    foreach (range(1, ChannexClient::CALLS_PER_MINUTE) as $call) {
        $client->post('/restrictions', ariBody());
    }

    expect(fn () => $client->post('/restrictions', ariBody()))->toThrow(ChannexRetryLater::class);
    Http::assertSentCount(ChannexClient::CALLS_PER_MINUTE);
});

test('the budget is kept per endpoint and per property', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    $client = app(ChannexClient::class);

    foreach (range(1, ChannexClient::CALLS_PER_MINUTE) as $call) {
        $client->post('/restrictions', ariBody());
    }

    $client->post('/availability', ariBody());
    $client->post('/restrictions', ariBody('prop-2'));

    Http::assertSentCount(ChannexClient::CALLS_PER_MINUTE + 2);
});

test('calls outside the ari endpoints are never limited', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    $client = app(ChannexClient::class);

    foreach (range(1, 8) as $call) {
        $client->get('/channels/list');
    }

    Http::assertSentCount(8);
});

test('a 429 asks the caller to retry later and honours Retry-After', function () {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '90'])]);

    try {
        app(ChannexClient::class)->post('/restrictions', ariBody());
        $this->fail('Expected ChannexRetryLater.');
    } catch (ChannexRetryLater $exception) {
        expect($exception->retryAfter)->toBe(90)->and($exception->status)->toBe(429);
    }
});

test('a 5xx asks the caller to retry later after 60 seconds', function () {
    Http::fake(['*' => Http::response([], 503)]);

    try {
        app(ChannexClient::class)->get('/channels/list');
        $this->fail('Expected ChannexRetryLater.');
    } catch (ChannexRetryLater $exception) {
        expect($exception->retryAfter)->toBe(60)->and($exception->status)->toBe(503);
    }
});

test('a network error asks the caller to retry later', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    app(ChannexClient::class)->get('/channels/list');
})->throws(ChannexRetryLater::class, 'could not be reached');

test('a 422 is not retried and keeps the errors', function () {
    Http::fake(['*' => Http::response(['errors' => ['details' => ['rate' => ['is invalid']]]], 422)]);

    try {
        app(ChannexClient::class)->post('/restrictions', ariBody());
        $this->fail('Expected ChannexRequestFailed.');
    } catch (ChannexRequestFailed $exception) {
        expect($exception->status)->toBe(422)->and($exception->errors)->toHaveKey('details');
    }
});
