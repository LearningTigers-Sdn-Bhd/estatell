<?php

namespace App\Services\ApiAccess;

use Illuminate\Support\Str;

/**
 * Makes and hashes API secrets. Tokens and pairing codes are stored as SHA-256 hashes only: both are random, so a fast hash is enough.
 */
class ApiSecrets
{
    public const TOKEN_PREFIX = 'est_live_';

    /** No 0, 1, I, L or O: a code read aloud or typed from a screen stays unambiguous. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function newToken(): string
    {
        return self::TOKEN_PREFIX.Str::random(40);
    }

    /**
     * Eight characters, about 40 bits. Single use, valid ten minutes, and the pair endpoint is rate limited.
     */
    public static function newPairingCode(): string
    {
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $code;
    }

    /**
     * Drops spaces and dashes and upper-cases, so `abcd-2345` matches `ABCD2345`.
     */
    public static function normalizeCode(string $code): string
    {
        return Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
