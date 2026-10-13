<?php

namespace App\Services\Platform;

use App\Models\Partner;
use RuntimeException;

/**
 * Makes the 6-character code in a partner's signup link. It skips 0, O, 1 and I, so the link is easy to read out.
 */
class PartnerSignupCode
{
    public const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const int LENGTH = 6;

    private const int MAX_TRIES = 5;

    /**
     * A random code that no partner uses.
     */
    public function generate(): string
    {
        for ($try = 0; $try < self::MAX_TRIES; $try++) {
            $code = $this->random();

            if (! Partner::query()->where('signup_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Could not make a unique partner signup code.');
    }

    private function random(): string
    {
        $code = '';

        for ($position = 0; $position < self::LENGTH; $position++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
