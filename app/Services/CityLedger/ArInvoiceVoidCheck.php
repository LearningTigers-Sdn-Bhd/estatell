<?php

namespace App\Services\CityLedger;

use App\Enums\ArInvoiceStatus;
use App\Models\ArInvoice;

/**
 * Says why an AR invoice cannot be voided, in the words staff need: the credit notes to void and the payments to
 * reverse first. Null when it can. It mirrors the rule of `VoidArInvoice` and reads only.
 */
class ArInvoiceVoidCheck
{
    public function __construct(private ArInvoiceBalance $arInvoiceBalance) {}

    public function blockedBy(ArInvoice $invoice): ?string
    {
        $notes = $invoice->creditNotes()->where('status', '<>', ArInvoiceStatus::Void)->orderBy('id')->pluck('number')->all();
        $receipts = $this->arInvoiceBalance->activeReceiptNumbers($invoice);
        $reasons = [];

        if ($notes !== []) {
            $reasons[] = trans_choice('{1} Void credit note :numbers first.|[2,*] Void credit notes :numbers first.', count($notes), ['numbers' => ArInvoiceRefusals::listed($notes)]);
        }

        if ($receipts !== []) {
            $reasons[] = trans_choice('{1} Reverse payment :receipts first.|[2,*] Reverse payments :receipts first.', count($receipts), ['receipts' => ArInvoiceRefusals::listed($receipts)]);
        }

        if ($reasons === []) {
            return $invoice->status === ArInvoiceStatus::Open ? null : __('This invoice is not open.');
        }

        return implode(' ', $reasons);
    }
}
