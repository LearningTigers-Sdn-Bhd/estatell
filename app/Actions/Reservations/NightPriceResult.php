<?php

namespace App\Actions\Reservations;

/**
 * What `ChangeNightPrice` returns: how many nights changed and how many of them were charged again, or a refusal code
 * (its own, `UnitStayResult` and posting codes, and the quote refusals).
 */
class NightPriceResult
{
    public const REFUSAL_NIGHTS_INVALID = 'nights_invalid';

    public const REFUSAL_PRICE_INVALID = 'price_invalid';

    private function __construct(
        public readonly ?string $refusal,
        public readonly int $changed = 0,
        public readonly int $corrected = 0,
    ) {}

    public static function done(int $changed, int $corrected): self
    {
        return new self(null, $changed, $corrected);
    }

    public static function refused(string $refusal): self
    {
        return new self($refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
