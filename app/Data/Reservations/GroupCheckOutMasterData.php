<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The group bill (Master folio) line of the group check-out page: its balance, `name` (the payer, else the group name), whether it can take moved charges, and whether
 * **Settle Master to City ledger** is offered (a company payer with direct bill and `city-ledger.invoice`).
 */
class GroupCheckOutMasterData extends Data
{
    public function __construct(
        public int $folioId,
        public string $label,
        public string $balance,
        public string $name,
        public ?string $payerCompany,
        public bool $canSettle,
    ) {}
}
