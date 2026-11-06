<?php

namespace App\Actions\Reservations;

/**
 * What `ChangeUnitParty` returns: how many nights took a new price, how many of them were charged again and how many
 * charged add-ons were corrected, or the refusal code. A refusal writes and logs nothing.
 */
final readonly class UnitPartyResult
{
    private function __construct(
        public int $repriced,
        public int $corrected,
        public ?string $refusal,
        public int $adjustedAddOns = 0,
    ) {}

    public static function done(int $repriced, int $corrected, int $adjustedAddOns = 0): self
    {
        return new self($repriced, $corrected, null, $adjustedAddOns);
    }

    public static function refused(string $refusal): self
    {
        return new self(0, 0, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
