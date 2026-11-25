<?php

namespace App\Services\Pos;

use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Hashes a staff POS PIN with the dedicated `POS_PIN_PEPPER`, never `APP_KEY`.
 */
class PosPinHasher
{
    public function make(string $pin): string
    {
        return Hash::make($pin.$this->pepper());
    }

    public function check(string $pin, string $hash): bool
    {
        return Hash::check($pin.$this->pepper(), $hash);
    }

    private function pepper(): string
    {
        $pepper = config('pos.pin_pepper');

        if (! is_string($pepper) || $pepper === '') {
            throw new RuntimeException('POS_PIN_PEPPER is not set.');
        }

        return $pepper;
    }
}
