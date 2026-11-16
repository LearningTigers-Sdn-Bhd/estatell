<?php

namespace App\Services\Account;

/**
 * Names the network an IP address belongs to from its autonomous system number (ASN), which Cloudflare sends in the
 * `X-Client-ASN` header. Only the main Malaysian networks are named; any other number shows as `AS{number}`. See
 * docs/01-platform/07-sign-in-log.md.
 */
class NetworkNames
{
    /**
     * @var array<int, string>
     */
    private const array NAMES = [
        4788 => 'TM / Unifi',
        9534 => 'Maxis',
        10030 => 'Celcom',
        4818 => 'Digi',
        38466 => 'U Mobile',
        9930 => 'TIME',
    ];

    public function name(?int $asn): ?string
    {
        if ($asn === null) {
            return null;
        }

        return self::NAMES[$asn] ?? 'AS'.$asn;
    }
}
