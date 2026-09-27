<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_test/client-ip', fn () => request()->ip());
});

function clientIp(string $trustedProxies, string $remoteAddr, string $forwardedFor): string
{
    config(['trustedproxy.proxies' => $trustedProxies]);
    (new AppServiceProvider(app()))->boot();

    return test()
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->get('/_test/client-ip', ['X-Forwarded-For' => $forwardedFor])
        ->getContent();
}

test('resolves the guest ip behind cloudflare and traefik', function () {
    expect(clientIp('PRIVATE_SUBNETS,CLOUDFLARE', '172.18.0.2', '60.50.1.23, 162.158.1.1'))
        ->toBe('60.50.1.23');
});

test('stops at the cloudflare edge when cloudflare is not trusted', function () {
    expect(clientIp('PRIVATE_SUBNETS', '172.18.0.2', '60.50.1.23, 162.158.1.1'))
        ->toBe('162.158.1.1');
});

test('ignores forwarded headers from an untrusted caller', function () {
    expect(clientIp('PRIVATE_SUBNETS,CLOUDFLARE', '8.8.8.8', '60.50.1.23'))
        ->toBe('8.8.8.8');
});

test('trusts every hop when set to a wildcard', function () {
    expect(clientIp('*', '172.18.0.2', '60.50.1.23, 162.158.1.1'))
        ->toBe('60.50.1.23');
});
