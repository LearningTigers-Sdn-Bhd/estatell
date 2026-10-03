<?php

namespace App\Actions\Folio;

use App\Models\Folio;

/**
 * What `CloseFolio` and `ReopenFolio` return: the folio in its new status, or a refusal code.
 */
class FolioStatusResult
{
    public const REFUSAL_NOT_OPEN = 'folio_not_open';

    public const REFUSAL_NOT_CLOSED = 'folio_not_closed';

    public const REFUSAL_BALANCE_NOT_ZERO = 'balance_not_zero';

    private function __construct(
        public readonly ?Folio $folio,
        public readonly ?string $refusal,
    ) {}

    public static function done(Folio $folio): self
    {
        return new self($folio, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
