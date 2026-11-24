<?php

use App\Models\User;
use App\Services\Platform\DocsAuthToken;

test('guests cannot access the docs auth route', function () {
    $this->get(route('docs.auth'))
        ->assertRedirect(route('login'));
});

test('authenticated staff gets redirected to docs with a valid token', function () {
    config([
        'estatell.docs_url' => 'https://docs.example.test/',
        'estatell.docs_auth_secret' => 'test-secret-key-12345',
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('docs.auth'))
        ->assertRedirect();

    $targetUrl = $response->headers->get('Location');
    expect($targetUrl)->toStartWith('https://docs.example.test/en/docs?token=');

    parse_str((string) parse_url((string) $targetUrl, PHP_URL_QUERY), $query);
    expect($query)->toHaveKey('token');

    $payload = DocsAuthToken::verify($query['token'], 'test-secret-key-12345');
    expect($payload)->not->toBeNull()
        ->and($payload['sub'])->toBe($user->id)
        ->and($payload['email'])->toBe($user->email)
        ->and($payload['exp'])->toBeGreaterThan(now()->timestamp);
});

test('docs auth honors a valid return_to destination', function () {
    config([
        'estatell.docs_url' => 'https://docs.example.test/',
        'estatell.docs_auth_secret' => 'test-secret-key-12345',
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('docs.auth', ['return_to' => 'https://docs.example.test/ms/docs/reservations']))
        ->assertRedirect();

    $targetUrl = $response->headers->get('Location');
    expect($targetUrl)->toStartWith('https://docs.example.test/ms/docs/reservations?token=');
});

test('docs auth ignores untrusted open redirect destinations', function () {
    config([
        'estatell.docs_url' => 'https://docs.example.test/',
        'estatell.docs_auth_secret' => 'test-secret-key-12345',
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('docs.auth', ['return_to' => 'https://malicious.example.com/steal']))
        ->assertRedirect();

    $targetUrl = $response->headers->get('Location');
    expect($targetUrl)->toStartWith('https://docs.example.test/en/docs?token=');
});

test('docs token verification fails on invalid signature or expired token', function () {
    $user = User::factory()->create();

    $validToken = DocsAuthToken::generate($user, 'secret-a');
    expect(DocsAuthToken::verify($validToken, 'secret-a'))->not->toBeNull()
        ->and(DocsAuthToken::verify($validToken, 'wrong-secret'))->toBeNull();

    $expiredToken = DocsAuthToken::generate($user, 'secret-a', ttlSeconds: -10);
    expect(DocsAuthToken::verify($expiredToken, 'secret-a'))->toBeNull();
});

test('docs tokens use the development secret locally when none is set', function () {
    config(['estatell.docs_auth_secret' => null]);

    $token = DocsAuthToken::generate(User::factory()->create());

    expect(DocsAuthToken::verify($token, DocsAuthToken::DEVELOPMENT_SECRET))->not->toBeNull();
});

test('docs tokens refuse the development secret outside local and testing', function () {
    config(['estatell.docs_auth_secret' => null]);
    app()->detectEnvironment(fn (): string => 'production');

    DocsAuthToken::generate(User::factory()->create());
})->throws(RuntimeException::class, 'DOCS_AUTH_SECRET is not set.');

test('docs token verification rejects a payload with the wrong field types', function () {
    $encoded = rtrim(strtr(base64_encode(json_encode(['sub' => '1', 'email' => 'a@b.test', 'iat' => 1, 'exp' => now()->addHour()->getTimestamp()], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $token = $encoded.'.'.hash_hmac('sha256', $encoded, 'secret-a');

    expect(DocsAuthToken::verify($token, 'secret-a'))->toBeNull();
});
