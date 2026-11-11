<?php

namespace App\Actions\Commissions;

use App\Data\Commissions\CommissionEntryInputData;
use App\Enums\CommissionEntryType;
use App\Enums\PayoutVoucherStatus;
use App\Models\CommissionEntry;
use App\Models\PayoutVoucher;
use App\Models\Property;
use App\Services\Commissions\PayoutCycle;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The monthly run on the 16th: one voucher per property for the cycle that ended on the 15th, holding every unvouchered
 * entry due up to then, due on the 1st (or the next weekday). A small negative total (under RM 50) is carried forward
 * at once, unless the two vouchers before it were both carried: a `carried_forward` entry zeroes this voucher and its
 * opposite waits for the next one. Anything else is a Draft for an admin to check. A cycle that already has its voucher
 * is skipped, so a repeat run makes nothing. Returns how many vouchers it made.
 * See docs/30-commission-fees/04-payout-vouchers.md.
 */
class GenerateDraftVouchers
{
    public const string CARRY_LIMIT = '50.00';

    public const int MAX_CARRIES_IN_A_ROW = 2;

    public function __construct(
        private PostCommissionEntry $postCommissionEntry,
        private PayoutCycle $payoutCycle,
    ) {}

    public function __invoke(?CarbonImmutable $runOn = null): int
    {
        $periodEnd = $this->payoutCycle->lastEndedOn($runOn ?? $this->payoutCycle->today());

        $propertyIds = CommissionEntry::query()
            ->whereNull('payout_voucher_id')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $periodEnd->toDateString())
            ->distinct()
            ->orderBy('property_id')
            ->pluck('property_id');

        return $propertyIds->sum(fn (int $propertyId): int => (int) $this->generate(Property::query()->findOrFail($propertyId), $periodEnd));
    }

    private function generate(Property $property, CarbonImmutable $periodEnd): bool
    {
        return DB::transaction(function () use ($property, $periodEnd): bool {
            Property::query()->whereKey($property->id)->lockForUpdate()->first();

            if (PayoutVoucher::query()->where('property_id', $property->id)->where('period_end', $periodEnd->toDateString())->exists()) {
                return false;
            }

            $entries = CommissionEntry::query()
                ->where('property_id', $property->id)
                ->whereNull('payout_voucher_id')
                ->whereNotNull('due_date')
                ->where('due_date', '<=', $periodEnd->toDateString())
                ->get(['id', 'amount']);

            $amount = $entries->reduce(fn (BigDecimal $sum, CommissionEntry $entry): BigDecimal => $sum->plus($entry->amount), BigDecimal::zero())->toScale(2);
            $carries = $this->carries($property, $amount);

            $voucher = PayoutVoucher::query()->create([
                'property_id' => $property->id,
                'number' => 'PV-'.$periodEnd->format('Ym').'-'.$property->code,
                'period_start' => $this->payoutCycle->startOf($periodEnd)->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'due_on' => $this->payoutCycle->payoutDate($periodEnd)->toDateString(),
                'amount' => (string) $amount,
                'status' => $carries ? PayoutVoucherStatus::CarriedForward : PayoutVoucherStatus::Draft,
            ]);

            CommissionEntry::query()->whereIn('id', $entries->pluck('id'))->update(['payout_voucher_id' => $voucher->id]);

            if ($carries) {
                $this->carryForward($property, $voucher, $amount, $periodEnd);
            }

            return true;
        });
    }

    /**
     * Whether a negative total under the limit moves to the next cycle: not when the two vouchers before were both carried.
     */
    private function carries(Property $property, BigDecimal $amount): bool
    {
        if (! $amount->isNegative() || $amount->negated()->isGreaterThanOrEqualTo(self::CARRY_LIMIT)) {
            return false;
        }

        $previous = PayoutVoucher::query()
            ->where('property_id', $property->id)
            ->orderByDesc('period_end')
            ->limit(self::MAX_CARRIES_IN_A_ROW)
            ->pluck('status');

        return $previous->count() < self::MAX_CARRIES_IN_A_ROW
            || $previous->contains(fn (PayoutVoucherStatus $status): bool => $status !== PayoutVoucherStatus::CarriedForward);
    }

    /**
     * Zeroes the voucher with an opposite entry inside it, and leaves the amount for the next cycle on its first day.
     */
    private function carryForward(Property $property, PayoutVoucher $voucher, BigDecimal $amount, CarbonImmutable $periodEnd): void
    {
        $closing = ($this->postCommissionEntry)($property, new CommissionEntryInputData(
            type: CommissionEntryType::CarriedForward,
            amount: (string) $amount->negated(),
            dueDate: $periodEnd,
            idempotencyKey: 'carried-out:'.$voucher->id,
        ));
        CommissionEntry::query()->whereKey($closing->id)->whereNull('payout_voucher_id')->update(['payout_voucher_id' => $voucher->id]);

        ($this->postCommissionEntry)($property, new CommissionEntryInputData(
            type: CommissionEntryType::CarriedForward,
            amount: (string) $amount,
            dueDate: $periodEnd->addDay(),
            idempotencyKey: 'carried:'.$voucher->id,
        ));
    }
}
