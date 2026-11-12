<?php

namespace App\Services\Commissions;

use App\Data\Commissions\CommissionTotalsData;
use App\Data\Property\CurrencyData;
use App\Enums\PayoutVoucherStatus;
use App\Models\CommissionEntry;
use App\Models\PayoutVoucher;
use App\Services\BookingEngine\OnlineBookingMoney;
use Brick\Math\BigDecimal;
use Brick\Money\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The three figures at the top of the admin Commissions page, as of now. They follow the property filter and ignore the
 * period. Every amount is in ringgit, the currency Curlec settles in. See docs/30-commission-fees/03-ledger.md#totals.
 */
class CommissionTotals
{
    public function __construct(
        private OnlineBookingMoney $onlineBookingMoney,
        private PayoutCycle $payoutCycle,
    ) {}

    public function for(?int $propertyId = null): CommissionTotalsData
    {
        $balances = $this->unpaidBalances($propertyId);
        $positive = $balances->filter(fn (BigDecimal $balance): bool => $balance->isPositive());
        $negative = $balances->filter(fn (BigDecimal $balance): bool => $balance->isNegative());
        [$payable, $payableOn] = $this->immediatePayable($propertyId);

        return new CommissionTotalsData(
            cashLiability: (string) $this->sum($positive)->plus($this->onlineBookingMoney->heldOutsideLedger($propertyId))->toScale(2),
            immediatePayable: (string) $payable->toScale(2),
            payableOn: $payableOn,
            owedToEstatell: (string) $this->sum($negative)->negated()->toScale(2),
            propertiesOwing: $negative->count(),
            currency: CurrencyData::fromCurrency(Currency::of('MYR')),
        );
    }

    /**
     * Each property's balance of entries not yet settled: unvouchered, or in a Draft or Approved voucher.
     *
     * @return Collection<int, BigDecimal>
     */
    private function unpaidBalances(?int $propertyId): Collection
    {
        return $this->unsettled(CommissionEntry::query(), $propertyId)
            ->selectRaw('property_id, sum(amount) as balance')
            ->groupBy('property_id')
            ->pluck('balance', 'property_id')
            ->map(fn (mixed $balance): BigDecimal => BigDecimal::of((string) $balance));
    }

    /**
     * Unpaid Draft and Approved vouchers with the earliest payout date; with none, a forecast of the coming cut-off from
     * the unvouchered entries. Only properties Estatell pays count.
     *
     * @return array{0: BigDecimal, 1: string}
     */
    private function immediatePayable(?int $propertyId): array
    {
        $open = PayoutVoucher::query()
            ->whereIn('status', [PayoutVoucherStatus::Draft, PayoutVoucherStatus::Approved])
            ->when($propertyId !== null, fn (Builder $query) => $query->where('property_id', $propertyId));
        $nextDue = $open->clone()->min('due_on');

        if ($nextDue !== null) {
            $amounts = $open->clone()->where('due_on', $nextDue)->where('amount', '>', 0)->pluck('amount');

            return [$this->sum($amounts->map(fn (string $amount): BigDecimal => BigDecimal::of($amount))), (string) $nextDue];
        }

        $cutOff = $this->payoutCycle->currentEndOn($this->payoutCycle->today());
        $forecast = CommissionEntry::query()
            ->whereNull('payout_voucher_id')
            ->where('due_date', '<=', $cutOff->toDateString())
            ->when($propertyId !== null, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->selectRaw('property_id, sum(amount) as balance')
            ->groupBy('property_id')
            ->pluck('balance')
            ->map(fn (mixed $balance): BigDecimal => BigDecimal::of((string) $balance))
            ->filter(fn (BigDecimal $balance): bool => $balance->isPositive());

        return [$this->sum($forecast), $this->payoutCycle->payoutDate($cutOff)->toDateString()];
    }

    /**
     * @param  Builder<CommissionEntry>  $entries
     * @return Builder<CommissionEntry>
     */
    private function unsettled(Builder $entries, ?int $propertyId): Builder
    {
        return $entries
            ->where(fn (Builder $query) => $query
                ->whereNull('payout_voucher_id')
                ->orWhereExists(fn (QueryBuilder $vouchers) => $vouchers
                    ->from('payout_vouchers')
                    ->whereColumn('payout_vouchers.id', 'commission_entries.payout_voucher_id')
                    ->whereIn('payout_vouchers.status', [PayoutVoucherStatus::Draft->value, PayoutVoucherStatus::Approved->value])))
            ->when($propertyId !== null, fn (Builder $query) => $query->where('property_id', $propertyId));
    }

    /**
     * @param  iterable<BigDecimal>  $amounts
     */
    private function sum(iterable $amounts): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }
}
