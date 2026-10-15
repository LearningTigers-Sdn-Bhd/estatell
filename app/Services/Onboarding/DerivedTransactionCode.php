<?php

namespace App\Services\Onboarding;

use App\Models\Property;

/**
 * The transaction code a new onboarding row gets from its name: the name in capitals with underscores (Extra bed →
 * EXTRA_BED), at most 20 characters, and a number when a transaction code of the property already has it. Settings can
 * edit it later.
 */
class DerivedTransactionCode
{
    private const CODE_LENGTH = 20;

    public function forName(Property $property, string $name, string $fallback): string
    {
        $base = trim((string) preg_replace('/[^A-Z0-9]+/', '_', mb_strtoupper($name)), '_');
        $base = $base === '' ? $fallback : $base;

        for ($number = 1; ; $number++) {
            $suffix = $number === 1 ? '' : "_{$number}";
            $candidate = trim(substr($base, 0, self::CODE_LENGTH - strlen($suffix)), '_').$suffix;

            $taken = $property->transactionCodes()->whereRaw('lower(code) = ?', [mb_strtolower($candidate)])->exists();

            if (! $taken) {
                return $candidate;
            }
        }
    }
}
