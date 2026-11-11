<?php

namespace App\Data\Portal;

use App\Data\Catalogues\BankAccountData;
use Spatie\LaravelData\Data;

/**
 * The How to pay card of the portal booking's Payments tab. `billedLater` is a direct-billing company, which pays its
 * invoice from Statements and sees a note instead. `leftToPay` is the agent's part of the bill less what is paid on the
 * folios the company pays. `canSendProof` shows the proof form. The contact shows when the property has no available
 * bank account. See docs/15-city-ledger/06-agent-payment-schedule.md#payment-proof.
 */
class PortalHowToPayData extends Data
{
    /**
     * @param  list<BankAccountData>  $bankAccounts
     */
    public function __construct(
        public bool $billedLater,
        public string $paid,
        public string $leftToPay,
        public array $bankAccounts,
        public bool $canSendProof,
        public ?string $contactPhone,
        public ?string $contactEmail,
    ) {}
}
