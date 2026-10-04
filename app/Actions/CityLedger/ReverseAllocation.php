<?php

namespace App\Actions\CityLedger;

use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReverseAllocation
{
    public function __construct(private SyncArInvoiceStatus $syncArInvoiceStatus) {}

    /**
     * Reverse an allocation with a required reason. The money goes back to the payment as unallocated credit, and the
     * invoice becomes Open again when it owes money. The allocation row is locked first, then its payment and
     * invoice, and a refusal writes and logs nothing. Permissions are checked by the route.
     * See docs/15-city-ledger/03-ar-payments.md.
     */
    public function __invoke(User $actor, ArAllocation $allocation, string $reason): ArPaymentResult
    {
        return DB::transaction(function () use ($actor, $allocation, $reason): ArPaymentResult {
            $locked = ArAllocation::query()->lockForUpdate()->findOrFail($allocation->id);

            if ($locked->reversed_at !== null) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_ALREADY_REVERSED);
            }

            if (trim($reason) === '') {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_REASON_REQUIRED);
            }

            $payment = ArPayment::query()->lockForUpdate()->findOrFail($locked->ar_payment_id);
            $invoice = ArInvoice::query()->lockForUpdate()->findOrFail($locked->ar_invoice_id);

            $locked->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => trim($reason),
            ])->save();

            ($this->syncArInvoiceStatus)($invoice);

            activity()
                ->performedOn($payment->company)
                ->causedBy($actor)
                ->event('ar_allocation.reversed')
                ->withProperties([
                    'property_id' => $payment->property_id,
                    'ar_payment_id' => $payment->id,
                    'ar_invoice_id' => $invoice->id,
                    'ar_allocation_id' => $locked->id,
                    'amount' => $locked->amount,
                    'reason' => $locked->reversal_reason,
                ])
                ->log('AR allocation reversed');

            return ArPaymentResult::done($payment);
        });
    }
}
