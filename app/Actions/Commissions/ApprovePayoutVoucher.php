<?php

namespace App\Actions\Commissions;

use App\Enums\PayoutVoucherStatus;
use App\Models\PayoutVoucher;
use App\Models\User;
use App\Services\Commissions\PayoutVoucherTotals;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Locks a Draft voucher: it saves the bank account to pay and the totals by type and service in `snapshot`, so later
 * edits to the property's bank accounts never change it. A positive voucher needs an active bank account. Returns a
 * refusal code, or null when approved. See docs/30-commission-fees/04-payout-vouchers.md#flow.
 */
class ApprovePayoutVoucher
{
    public const string REFUSAL_NOT_DRAFT = 'not_draft';

    public const string REFUSAL_NO_BANK_ACCOUNT = 'no_bank_account';

    public function __construct(private PayoutVoucherTotals $totals) {}

    public function __invoke(User $admin, PayoutVoucher $voucher): ?string
    {
        return DB::transaction(function () use ($admin, $voucher): ?string {
            $locked = PayoutVoucher::query()->with('property')->lockForUpdate()->findOrFail($voucher->id);

            if (! $locked->status->canTransitionTo(PayoutVoucherStatus::Approved)) {
                return self::REFUSAL_NOT_DRAFT;
            }

            $account = $locked->property->bankAccounts()->active()->ordered()->first();

            if ($account === null && BigDecimal::of($locked->amount)->isPositive()) {
                return self::REFUSAL_NO_BANK_ACCOUNT;
            }

            $locked->update([
                'status' => PayoutVoucherStatus::Approved,
                'approved_by' => $admin->id,
                'approved_at' => now(),
                'snapshot' => [
                    'bank_account' => $account === null ? null : [
                        'bank_name' => $account->bank_name,
                        'account_name' => $account->account_name,
                        'account_number' => $account->account_number,
                        'swift_code' => $account->swift_code,
                    ],
                    'totals' => $this->totals->for($locked),
                ],
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($admin)
                ->event('approved')
                ->withProperties(['property_id' => $locked->property_id, 'number' => $locked->number, 'amount' => $locked->amount])
                ->log('Payout voucher approved');

            return null;
        });
    }
}
