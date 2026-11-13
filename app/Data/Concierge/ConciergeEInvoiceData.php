<?php

namespace App\Data\Concierge;

use App\Enums\ConciergeEInvoiceState;
use Spatie\LaravelData\Data;

/**
 * What the Concierge e-invoice page shows. `buyer` is set only while the guest can request; `rejection` only when LHDN
 * refused the guest's last request; `viewUrl` only once the e-invoice is Valid.
 */
class ConciergeEInvoiceData extends Data
{
    public function __construct(
        public ConciergeEInvoiceState $state,
        public string $title,
        public string $message,
        public ?string $invoiceNumber,
        public ?string $viewUrl,
        public ?ConciergeEInvoiceBuyerData $buyer,
        public ?string $rejection,
    ) {}
}
