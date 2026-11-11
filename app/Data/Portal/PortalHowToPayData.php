<?php

namespace App\Data\Portal;

use App\Data\Catalogues\BankAccountData;
use Spatie\LaravelData\Data;

/**
 * How to pay on the portal booking's Payments tab. `billedLater` is a direct-billing company, which sees that the
 * booking is invoiced after checkout. `leftToPay` is the agent's part of the bill less what is paid on the folios the
 * company pays; once the booking is invoiced it is that invoice's balance (`invoiceNumber`, `invoiceDueOn`).
 * `canSendProof` shows the Pay button. The contact shows when the property has no available bank account. See docs/15-city-ledger/06-agent-payment-schedule.md#payment-proof.
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
        public ?string $invoiceNumber = null,
        public ?string $invoiceDueOn = null,
    ) {}
}
