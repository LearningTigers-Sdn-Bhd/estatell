<?php

namespace App\Services\BookingEngine;

use Illuminate\Support\Str;

/**
 * A place read from a Google Maps link. The fingerprint is the normalized name and the coordinates rounded to four
 * decimals (about 11 m), so the same place pasted twice is one place.
 */
final readonly class MapsPlace
{
    public string $fingerprint;

    public function __construct(
        public string $name,
        public string $latitude,
        public string $longitude,
        public string $url,
    ) {
        $this->fingerprint = self::fingerprintFor($name, $latitude, $longitude);
    }

    public static function fingerprintFor(string $name, string $latitude, string $longitude): string
    {
        $normalized = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::lower($name)));

        return $normalized.'|'.number_format((float) $latitude, 4, '.', '').'|'.number_format((float) $longitude, 4, '.', '');
    }
}
