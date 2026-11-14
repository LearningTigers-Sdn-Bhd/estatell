<?php

use App\Services\Account\DeviceDescriber;

it('names the operating system and browser of a user agent', function (?string $userAgent, ?string $os, ?string $browser) {
    expect((new DeviceDescriber)->describe($userAgent))->toBe(['os' => $os, 'browser' => $browser]);
})->with([
    'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'Windows', 'Chrome'],
    'Edge on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0', 'Windows', 'Edge'],
    'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 'iOS', 'Safari'],
    'Chrome on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/131.0.6778.73 Mobile/15E148 Safari/604.1', 'iOS', 'Chrome'],
    'Samsung Internet on Android' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36', 'Android', 'Samsung Internet'],
    'Firefox on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:132.0) Gecko/20100101 Firefox/132.0', 'macOS', 'Firefox'],
    'Opera on Linux' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 OPR/115.0.0.0', 'Linux', 'Opera'],
    'unknown agent' => ['curl/8.7.1', null, null],
    'no agent' => [null, null, null],
]);
