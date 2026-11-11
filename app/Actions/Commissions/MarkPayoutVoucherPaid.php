<?php

namespace App\Actions\Commissions;

use App\Enums\PayoutVoucherStatus;
use App\Models\PayoutVoucher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records the bank transfer of an Approved voucher: Estatell paid the property, or the property paid Estatell. A paid
 * voucher never changes. Returns false when the voucher is not Approved. See docs/30-commission-fees/04-payout-vouchers.md#payment.
 */
class MarkPayoutVoucherPaid
{
    public function __invoke(User $admin, PayoutVoucher $voucher, CarbonImmutable $paidOn, string $reference): bool
    {
        return DB::transaction(function () use ($admin, $voucher, $paidOn, $reference): bool {
            $locked = PayoutVoucher::query()->lockForUpdate()->findOrFail($voucher->id);

            if (! $locked->status->canTransitionTo(PayoutVoucherStatus::Paid)) {
                return false;
            }

            $locked->update([
                'status' => PayoutVoucherStatus::Paid,
                'paid_by' => $admin->id,
                'paid_on' => $paidOn->toDateString(),
                'paid_reference' => trim($reference),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($admin)
                ->event('paid')
                ->withProperties(['property_id' => $locked->property_id, 'number' => $locked->number, 'amount' => $locked->amount, 'paid_on' => $paidOn->toDateString()])
                ->log('Payout voucher marked as paid');

            return true;
        });
    }
}
