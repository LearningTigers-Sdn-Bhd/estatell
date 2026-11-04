<?php

namespace App\Actions\Kiosk;

use Carbon\CarbonImmutable;

final readonly class KioskCardResult
{
    private function __construct(public ?CarbonImmutable $validUntil, public ?string $refusal) {}

    public static function registered(CarbonImmutable $validUntil): self
    {
        return new self($validUntil, null);
    }

    /**
     * @param  string  $code  `card_unknown` or `lock_unavailable`.
     */
    public static function refused(string $code): self
    {
        return new self(null, $code);
    }
}
