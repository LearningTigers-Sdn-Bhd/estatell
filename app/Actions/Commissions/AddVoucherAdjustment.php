<?php

namespace App\Actions\Commissions;

use App\Data\Commissions\CommissionEntryInputData;
use App\Enums\CommissionEntryType;
use App\Enums\PayoutVoucherStatus;
use App\Models\CommissionEntry;
use App\Models\PayoutVoucher;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Adds an adjustment entry to a Draft voucher, linked to it at once, and moves the voucher total by its amount. The
 * amount is signed from the property's side. The form's key makes a repeated submit add nothing. Returns false when
 * the voucher is no longer a Draft. See docs/30-commission-fees/05-platform-pages.md#voucher-page.
 */
class AddVoucherAdjustment
{
    public function __construct(private PostCommissionEntry $postCommissionEntry) {}

    public function __invoke(User $admin, PayoutVoucher $voucher, string $amount, string $reason, string $idempotencyKey): bool
    {
        return DB::transaction(function () use ($admin, $voucher, $amount, $reason, $idempotencyKey): bool {
            $locked = PayoutVoucher::query()->with('property')->lockForUpdate()->findOrFail($voucher->id);

            if ($locked->status !== PayoutVoucherStatus::Draft) {
                return false;
            }

            $entry = ($this->postCommissionEntry)($locked->property, new CommissionEntryInputData(
                type: CommissionEntryType::Adjustment,
                amount: (string) BigDecimal::of($amount)->toScale(2),
                dueDate: $locked->period_end,
                idempotencyKey: 'adjustment:'.$idempotencyKey,
                reason: trim($reason),
            ), $admin);

            if ($entry->payout_voucher_id !== null) {
                return true;
            }

            CommissionEntry::query()->whereKey($entry->id)->update(['payout_voucher_id' => $locked->id]);
            $locked->update(['amount' => (string) BigDecimal::of($locked->amount)->plus($entry->amount)->toScale(2)]);

            activity()
                ->performedOn($locked)
                ->causedBy($admin)
                ->event('adjusted')
                ->withProperties(['property_id' => $locked->property_id, 'number' => $locked->number, 'amount' => $entry->amount, 'reason' => $entry->reason])
                ->log('Payout voucher adjusted');

            return true;
        });
    }
}
