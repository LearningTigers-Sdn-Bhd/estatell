<?php

namespace App\Actions\Folio;

use App\Models\Folio;

/**
 * What `CloseFolio` and `ReopenFolio` return: the folio in its new status, or a refusal code. `units_in_house` also
 * names the rooms that are still in-house, and `folio_receives_scheduled_charges` the rooms with their dates.
 */
class FolioStatusResult
{
    public const REFUSAL_NOT_OPEN = 'folio_not_open';

    public const REFUSAL_NOT_CLOSED = 'folio_not_closed';

    public const REFUSAL_BALANCE_NOT_ZERO = 'balance_not_zero';

    public const REFUSAL_HAS_AR_INVOICE = 'folio_has_ar_invoice';

    public const REFUSAL_HAS_E_INVOICE = 'folio_has_e_invoice';

    public const REFUSAL_UNITS_IN_HOUSE = 'units_in_house';

    public const REFUSAL_RECEIVES_SCHEDULED_CHARGES = 'folio_receives_scheduled_charges';

    public const REFUSAL_ONLINE_REFUND_PENDING = 'online_refund_pending';

    private function __construct(
        public readonly ?Folio $folio,
        public readonly ?string $refusal,
        /** @var list<string> */
        public readonly array $rooms = [],
    ) {}

    public static function done(Folio $folio): self
    {
        return new self($folio, null);
    }

    /**
     * @param  list<string>  $rooms
     */
    public static function refused(string $refusal, array $rooms = []): self
    {
        return new self(null, $refusal, $rooms);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }

    public function isDone(): bool
    {
        return $this->refusal === null;
    }
}
