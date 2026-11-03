<?php

namespace App\Services\BookingEngine;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads a place name and coordinates from a Google Maps link. It makes no Google API call. A short `maps.app.goo.gl`
 * link is followed through one redirect on the server, for that host only. See
 * docs/28-booking-engine/06-content-registries.md#reading-a-google-maps-link.
 */
class GoogleMapsLink
{
    private const array LONG_HOSTS = ['google.com', 'www.google.com', 'maps.google.com'];

    private const string SHORT_HOST = 'maps.app.goo.gl';

    private const string NUMBER = '-?\d+(?:\.\d+)?';

    /**
     * @throws InvalidMapsLink
     */
    public function parse(string $link): MapsPlace
    {
        $link = trim($link);
        $host = $this->host($link);

        if ($host === self::SHORT_HOST) {
            $link = $this->followShortLink($link);
            $host = $this->host($link);
        }

        if (! in_array($host, self::LONG_HOSTS, true)) {
            throw new InvalidMapsLink(__('Enter a full Google Maps link.'));
        }

        $name = $this->name($link);

        if ($name === null) {
            throw new InvalidMapsLink(__('This link has no place name. Open the place on Google Maps and copy its link.'));
        }

        $coordinates = $this->coordinates($link);

        if ($coordinates === null || abs((float) $coordinates[0]) > 90 || abs((float) $coordinates[1]) > 180) {
            throw new InvalidMapsLink(__('This link has no valid location. Open the place on Google Maps and copy its link.'));
        }

        return new MapsPlace($name, $coordinates[0], $coordinates[1], $link);
    }

    private function host(string $link): ?string
    {
        $parts = parse_url($link);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }

        return strtolower($parts['host'] ?? '') ?: null;
    }

    private function followShortLink(string $link): string
    {
        try {
            $location = Http::withoutRedirecting()->timeout(5)->get($link)->header('Location');
        } catch (ConnectionException) {
            throw new InvalidMapsLink(__('That link could not be opened. Try the full link from Google Maps.'));
        }

        if ($location === '' || ! in_array($this->host($location), self::LONG_HOSTS, true)) {
            throw new InvalidMapsLink(__('That link could not be read. Try the full link from Google Maps.'));
        }

        return $location;
    }

    private function name(string $link): ?string
    {
        if (! preg_match('#/maps/place/([^/?\#]+)#i', (string) parse_url($link, PHP_URL_PATH), $match)) {
            return null;
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', str_replace('+', ' ', rawurldecode($match[1]))));

        return $name === '' ? null : $name;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function coordinates(string $link): ?array
    {
        $number = self::NUMBER;

        if (preg_match_all("/!3d({$number})!4d({$number})/i", $link, $matches, PREG_SET_ORDER)) {
            $last = end($matches);

            return [$last[1], $last[2]];
        }

        if (preg_match_all("/@({$number}),({$number})(?:,|\/|\?|#|$)/i", $link, $matches, PREG_SET_ORDER)) {
            $last = end($matches);

            return [$last[1], $last[2]];
        }

        return null;
    }
}
