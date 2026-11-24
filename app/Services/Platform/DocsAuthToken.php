<?php

namespace App\Services\Platform;

use App\Models\User;

class DocsAuthToken
{
    /**
     * Generate an HMAC-signed token for an authenticated user to access the docs site.
     */
    public static function generate(User $user, ?string $secret = null, int $ttlSeconds = 604800): string
    {
        $secret ??= self::secret();

        $payload = [
            'sub' => $user->id,
            'email' => $user->email,
            'iat' => now()->timestamp,
            'exp' => now()->timestamp + $ttlSeconds,
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

            if (! is_array($payload) || ! isset($payload['exp']) || $payload['exp'] < now()->timestamp) {
                return null;
            }

            return $payload;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function secret(): string
    {
        return (string) (config('estatell.docs_auth_secret') ?: config('app.key') ?: 'estatell-docs-dev-secret');
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
