<?php

namespace App\Actions\Folio;

/**
 * What `WaiveGroupTax` returns: how many units got a waiver, how many were skipped because they already had every
 * chosen tax waived, and how many posted tax lines were reversed, or the refusal code. A refusal writes and logs
 * nothing.
 */
final readonly class GroupTaxWaiverResult
{
    private function __construct(
        public int $waived,
        public int $skipped,
        public int $reversed,
        public ?string $refusal,
    ) {}

    public static function done(int $waived, int $skipped, int $reversed): self
    {
        return new self($waived, $skipped, $reversed, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(0, 0, 0, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
