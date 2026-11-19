<?php

namespace App\Services\Account;

use Illuminate\Http\Request;

/**
 * Reads where a request comes from, from Cloudflare's visitor location headers. Every part is null when missing.
 */
class VisitorLocation
{
    /**
     * The country Cloudflare found for the IP. Cloudflare sends `XX` when it does not know and `T1` for Tor, so only
     * two letters other than `XX` count.
     */
    public function countryCode(Request $request): ?string
    {
        $code = strtoupper((string) $request->header('CF-IPCountry'));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== 'XX' ? $code : null;
    }

    /**
     * A city or region from Cloudflare's visitor location headers, cut to 80 characters.
     */
    public function place(Request $request, string $header): ?string
    {
        $value = trim((string) $request->header($header));

        return $value === '' ? null : mb_substr($value, 0, 80);
    }

    /**
     * City and country for people to read, for example "Kota Kinabalu, MY". Null when neither is known.
     */
    public function label(Request $request): ?string
    {
        return collect([$this->place($request, 'cf-ipcity'), $this->countryCode($request)])->filter()->implode(', ') ?: null;
    }
}
