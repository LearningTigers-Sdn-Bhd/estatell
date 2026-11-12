<?php

namespace App\Actions\Folio;

/**
 * What `WaiveUnitTax` and `RemoveUnitTaxWaiver` return: how many posted tax lines were reversed, or the refusal code.
 * A refusal writes and logs nothing.
 */
final readonly class TaxWaiverResult
{
    public const string REFUSAL_UNIT_NOT_WAIVABLE = 'unit_not_waivable';

    public const string REFUSAL_TAX_NOT_FOUND = 'tax_not_found';

    public const string REFUSAL_ALREADY_WAIVED = 'already_waived';

    public const string REFUSAL_WAIVER_NOT_FOUND = 'waiver_not_found';

    private function __construct(
        public int $reversed,
        public ?string $refusal,
    ) {}

    public static function done(int $reversed = 0): self
    {
        return new self($reversed, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(0, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
