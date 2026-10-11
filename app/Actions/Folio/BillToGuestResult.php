<?php

namespace App\Actions\Folio;

use App\Models\Folio;

/**
 * What `BillToGuest` returns: the folio that now bills the guest, or a refusal code. `created` says the folio was
 * made by this call.
 */
class BillToGuestResult
{
    public const REFUSAL_GUEST_NOT_ON_UNIT = 'guest_not_on_unit';

    public const REFUSAL_UNIT_NOT_BILLABLE = 'unit_not_billable';

    public const REFUSAL_FOLIO_NOT_OPEN = 'folio_not_open';

    public const REFUSAL_ALREADY_BILLED = 'already_billed';

    private function __construct(
        public readonly ?Folio $folio,
        public readonly bool $created,
        public readonly ?string $refusal,
    ) {}

    public static function billed(Folio $folio, bool $created): self
    {
        return new self($folio, $created, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, false, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
