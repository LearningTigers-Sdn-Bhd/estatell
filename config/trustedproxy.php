<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of proxies whose X-Forwarded-* headers are trusted.
    | Accepts IPs, CIDR ranges, "*" (trust all), "PRIVATE_SUBNETS" (the
    | Docker network, where Traefik runs) and "CLOUDFLARE" (expanded to the
    | ranges below). Production uses "*": the app port is not published and
    | only Traefik can reach it. "PRIVATE_SUBNETS,CLOUDFLARE" is the tighter
    | option if the app is ever reachable another way.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', 'PRIVATE_SUBNETS'),

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Ranges
    |--------------------------------------------------------------------------
    |
    | Source: https://www.cloudflare.com/ips-v4 and /ips-v6 (checked
    | 2026-09-27). Cloudflare rarely changes these; recheck on incidents
    | where request()->ip() shows a Cloudflare address.
    |
    */

    'cloudflare' => [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],

];
