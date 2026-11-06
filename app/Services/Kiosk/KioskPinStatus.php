<?php

namespace App\Services\Kiosk;

use Carbon\CarbonImmutable;

/**
 * The PIN a kiosk shows: `pending` while TTLock has not answered, `ready` with the code and its window, or `failed`.
 */
final readonly class KioskPinStatus
{
    public const string PENDING = 'pending';

    public const string READY = 'ready';

    public const string FAILED = 'failed';

    private function __construct(public string $status, public ?string $code, public ?CarbonImmutable $validFrom, public ?CarbonImmutable $validUntil) {}

    public static function pending(): self
    {
        return new self(self::PENDING, null, null, null);
    }

    public static function failed(): self
    {
        return new self(self::FAILED, null, null, null);
    }

    public static function ready(string $code, CarbonImmutable $validFrom, CarbonImmutable $validUntil): self
    {
        return new self(self::READY, $code, $validFrom, $validUntil);
    }
}
