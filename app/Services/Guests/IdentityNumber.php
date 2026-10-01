<?php

namespace App\Services\Guests;

/**
 * Brings ID numbers to one form, so `900101-14-1234` and `900101141234` are the same number.
 */
class IdentityNumber
{
    /**
     * Uppercase letters and digits only.
     */
    public function normalize(string $number): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));
    }

    /**
     * The last four characters, behind dots, for lists.
     */
    public function mask(string $number): string
    {
        return '•••• '.substr($number, -4);
    }
}
