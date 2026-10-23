<?php

namespace App\Actions\CityLedger;

use App\Actions\Accounting\QueueCompanyBillingSync;
use App\Models\ArAllocation;
use App\Models\ArPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class VoidArPayment
{
    /**
     * Void a payment that has no active allocations, for example one recorded with a wrong amount. Reverse its
     * allocations first. The receipt number stays used. The payment row is locked first and a refusal writes and logs
     * nothing. Permissions are checked by the route. See docs/15-city-ledger/03-ar-payments.md.
     */
    public function __invoke(User $actor, ArPayment $payment): ArPaymentResult
    {
        return DB::transaction(function () use ($actor, $payment): ArPaymentResult {
            $locked = ArPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->voided_at !== null) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_ALREADY_VOID);
            }

            if (ArAllocation::query()->where('ar_payment_id', $locked->id)->active()->exists()) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_HAS_ALLOCATIONS);
            }

            $locked->forceFill(['voided_at' => now(), 'voided_by' => $actor->id])->save();

            app(QueueCompanyBillingSync::class)($locked->property_id);

            activity()
                ->performedOn($locked->company)
                ->causedBy($actor)
                ->event('ar_payment.voided')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'ar_payment_id' => $locked->id,
                    'receipt_number' => $locked->receipt_number,
                    'amount' => $locked->amount,
                ])
                ->log('AR payment voided');

            return ArPaymentResult::done($locked);
        });
    }
}
