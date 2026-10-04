<?php

namespace App\Services\CityLedger;

use App\Models\ArAllocation;
use App\Models\ArPayment;

/**
 * What an AR payment still has to allocate: its amount minus its active allocations, and 0 once it is voided.
 * Nothing is stored. See docs/15-city-ledger/03-ar-payments.md.
 */
class ArPaymentBalance
{
    public function unallocated(ArPayment $payment): string
    {
        if ($payment->voided_at !== null) {
            return '0.00';
        }

        $allocated = (string) ArAllocation::query()
            ->where('ar_payment_id', $payment->id)
            ->active()
            ->selectRaw('COALESCE(SUM(amount), 0)::numeric(14, 2) AS allocated')
            ->value('allocated');

        return bcsub((string) $payment->amount, $allocated, 2);
    }
}
