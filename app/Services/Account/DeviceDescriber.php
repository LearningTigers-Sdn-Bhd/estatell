<?php

namespace App\Services\Account;

/**
 * Turns a browser user agent into a short operating system and browser name, so the sign-in log keeps only those two
 * words and never the full user agent. Unknown agents give null. See docs/01-platform/07-sign-in-log.md.
 */
class DeviceDescriber
{
    /**
     * First match wins, so iPhone and Android come before Linux and Mac.
     *
     * @var array<string, string>
     */
    private const array OPERATING_SYSTEMS = [
        '/iPhone|iPod/' => 'iOS',
        '/iPad/' => 'iPadOS',
        '/Android/' => 'Android',
        '/CrOS/' => 'ChromeOS',
        '/Windows/' => 'Windows',
        '/Macintosh|Mac OS X/' => 'macOS',
        '/Linux/' => 'Linux',
    ];

    /**
     * First match wins, so browsers built on Chrome come before Chrome, and Chrome before Safari.
     *
     * @var array<string, string>
     */
    private const array BROWSERS = [
        '/Edg(e|A|iOS)?\//' => 'Edge',
        '/OPR\/|Opera/' => 'Opera',
        '/SamsungBrowser\//' => 'Samsung Internet',
        '/Firefox\/|FxiOS\//' => 'Firefox',
        '/Chrome\/|CriOS\//' => 'Chrome',
        '/Safari\//' => 'Safari',
    ];

    /**
     * @return array{os: string|null, browser: string|null}
     */
    public function describe(?string $userAgent): array
    {
        return [
            'os' => $this->firstMatch(self::OPERATING_SYSTEMS, (string) $userAgent),
            'browser' => $this->firstMatch(self::BROWSERS, (string) $userAgent),
        ];
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private function firstMatch(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }
}
