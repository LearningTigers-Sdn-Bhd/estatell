<?php

namespace App\Actions\CityLedger;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArInvoice;
use App\Services\CityLedger\ArInvoiceBalance;

class SyncArInvoiceStatus
{
    public function __construct(private ArInvoiceBalance $arInvoiceBalance) {}

    /**
     * Make a live invoice's stored status match its calculated balance: Settled at 0, Open above 0. A Void invoice
     * and a credit note are left alone. The caller holds the invoice row lock and a transaction.
     */
    public function __invoke(ArInvoice $invoice): void
    {
        if ($invoice->type !== ArInvoiceType::Invoice || $invoice->status === ArInvoiceStatus::Void) {
            return;
        }

        $status = bccomp($this->arInvoiceBalance->forInvoice($invoice), '0', 2) > 0 ? ArInvoiceStatus::Open : ArInvoiceStatus::Settled;

        if ($invoice->status !== $status) {
            $invoice->update(['status' => $status]);
        }
    }
}
