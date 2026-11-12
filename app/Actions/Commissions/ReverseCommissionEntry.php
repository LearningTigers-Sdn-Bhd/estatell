<?php

namespace App\Actions\Commissions;

use App\Data\Commissions\CommissionEntryInputData;
use App\Models\CommissionEntry;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an entry with an opposite entry linked by `reverses_entry_id`. The reversal takes the given due date, so it
 * lands in the current cycle while a paid voucher stays as it was. Returns null for a reversal or an entry already
 * reversed. See docs/30-commission-fees/02-commission-rules.md#corrections.
 */
class ReverseCommissionEntry
{
    public function __construct(private PostCommissionEntry $postCommissionEntry) {}

    public function __invoke(CommissionEntry $entry, ?CarbonImmutable $dueDate, ?User $actor = null): ?CommissionEntry
    {
        return DB::transaction(function () use ($entry, $dueDate, $actor): ?CommissionEntry {
            $entry = CommissionEntry::query()->with('property')->lockForUpdate()->findOrFail($entry->id);

            if ($entry->reverses_entry_id !== null || $entry->reversal()->exists()) {
                return null;
            }

            return ($this->postCommissionEntry)($entry->property, new CommissionEntryInputData(
                type: $entry->type,
                amount: (string) BigDecimal::of($entry->amount)->negated(),
                dueDate: $dueDate,
                reservationId: $entry->reservation_id,
                service: $entry->service,
                baseAmount: $entry->base_amount === null ? null : (string) BigDecimal::of($entry->base_amount)->negated(),
                rate: $entry->rate,
                gatewayPaymentId: $entry->gateway_payment_id,
                gatewayRefundId: $entry->gateway_refund_id,
                reversesEntryId: $entry->id,
                idempotencyKey: 'reversal:'.$entry->id,
                reason: $entry->reason,
            ), $actor);
        });
    }
}
