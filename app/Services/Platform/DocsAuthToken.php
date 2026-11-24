<?php

namespace App\Services\Platform;

use App\Models\User;
use RuntimeException;

class DocsAuthToken
{
    /**
     * Matches the docs site's development fallback, so local sign-in works without configuration.
     */
    public const DEVELOPMENT_SECRET = 'estatell-docs-dev-secret';

    /**
     * Generate an HMAC-signed token for an authenticated user to access the docs site.
     */
    public static function generate(User $user, ?string $secret = null, int $ttlSeconds = 604800): string
    {
        $secret ??= self::secret();

        $issuedAt = now()->getTimestamp();

        $payload = [
            'sub' => $user->id,
            'email' => $user->email,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttlSeconds,
        ];

        $encoded = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encoded, $secret);

        return $encoded.'.'.$signature;
    }

    /**
     * Verify an HMAC-signed token and return its payload if valid and unexpired.
     *
     * @return array{sub: int, email: string, iat: int, exp: int}|null
     */
    public static function verify(string $token, ?string $secret = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;
        $secret ??= self::secret();

        $expectedSignature = hash_hmac('sha256', $encoded, $secret);
        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        try {
            $json = self::base64UrlDecode($encoded);
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($payload)
                || ! is_int($payload['sub'] ?? null)
                || ! is_string($payload['email'] ?? null)
                || ! is_int($payload['iat'] ?? null)
                || ! is_int($payload['exp'] ?? null)
                || $payload['exp'] < now()->getTimestamp()) {
                return null;
            }

            return [
                'sub' => $payload['sub'],
                'email' => $payload['email'],
                'iat' => $payload['iat'],
                'exp' => $payload['exp'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The secret shared with the docs site. The development fallback is public, so it is used only locally and in tests.
     */
    private static function secret(): string
    {
        $secret = (string) config('estatell.docs_auth_secret');

        if ($secret !== '') {
            return $secret;
        }

        if (app()->environment('local', 'testing')) {
            return self::DEVELOPMENT_SECRET;
        }

        throw new RuntimeException('DOCS_AUTH_SECRET is not set. Set it to the same value as the docs site.');
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
