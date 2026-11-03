<?php

namespace App\Actions\Reservations;

/**
 * What `ChangeUnitParty` returns: how many nights took a new price and how many of them were charged again, or the
 * refusal code. A refusal writes and logs nothing.
 */
final readonly class UnitPartyResult
{
    private function __construct(
        public int $repriced,
        public int $corrected,
        public ?string $refusal,
    ) {}

    public static function done(int $repriced, int $corrected): self
    {
        return new self($repriced, $corrected, null);
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
